import { Message, TranslationLanguage } from "@/data/types"
import { MessageBubble } from "./MessageBubble"
import { getDayLabel, isSameDay, parseTemplateContent } from "./messageThreadUtils"
import { ThreadBackdrop } from "./ThreadBackdrop"
import { ChannelType } from "@/data/enums"
import { Fragment, useEffect, useRef, useLayoutEffect, useState, useMemo, useCallback } from "react"
import { Loader2, Search, X, ChevronUp, ChevronDown, RefreshCw } from "lucide-react"
import type { MessageTranslationResponse } from "@/lib/api/messages"
import { useTranslation } from "@/hooks/useTranslation"
import { Input } from "@/components/ui/input"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { MailMessageBlock } from "./MailMessageBlock"

/** Ventana para agrupar mensajes consecutivos del mismo emisor. */
const GROUPING_WINDOW_MS = 5 * 60 * 1000

/**
 * Identifica al emisor real para no mezclar, por ejemplo, un agente humano
 * con una respuesta automática del sistema aunque ambos sean salientes.
 */
const getMessageGroupKey = (message: Message) =>
  `${message.direction}:${message.sender_type}:${message.sender_id ?? "none"}`

interface MessageListProps {
  messages: Message[]
  onLoadMore: () => Promise<void>
  hasMore: boolean
  isLoadingMore: boolean
  onEditMessage?: (message: Message) => void
  onDeleteMessage?: (message: Message) => void
  currentUserId?: number
  isAdmin?: boolean
  translationLanguage: TranslationLanguage
  onTranslateMessage: (message: Message, targetLanguage: TranslationLanguage) => Promise<MessageTranslationResponse>
  channelType?: ChannelType
}

interface TranslationState {
  content?: string
  sourceContent: string
  targetLanguage: TranslationLanguage
  visible: boolean
  loading: boolean
  error?: string
}

export function MessageList({
  messages,
  onLoadMore,
  hasMore,
  isLoadingMore,
  onEditMessage,
  onDeleteMessage,
  currentUserId,
  isAdmin,
  translationLanguage,
  onTranslateMessage,
  channelType,
}: MessageListProps) {
  const { t, language } = useTranslation()
  const scrollRef = useRef<HTMLDivElement>(null)
  const prevScrollHeightRef = useRef(0)
  const lastMessageIdRef = useRef<number | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Message | null>(null)
  const [translations, setTranslations] = useState<Record<string, TranslationState>>({})

  // --- Chip flotante de fecha (estilo WhatsApp) ---
  const [floatingDay, setFloatingDay] = useState("")
  const [floatingVisible, setFloatingVisible] = useState(false)
  const floatingHideTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    return () => {
      if (floatingHideTimerRef.current) clearTimeout(floatingHideTimerRef.current)
    }
  }, [])

  // Determina el día del primer separador visible/superado y muestra el chip
  // mientras dura el scroll; se desvanece ~1s después de parar.
  const updateFloatingDay = useCallback(() => {
    const container = scrollRef.current
    if (!container) return
    const separators = container.querySelectorAll<HTMLElement>("[data-day-label]")
    if (separators.length < 2) return

    const containerTop = container.getBoundingClientRect().top
    let label = separators[0].dataset.dayLabel || ""
    for (const sep of separators) {
      if (sep.getBoundingClientRect().top - containerTop <= 12) {
        label = sep.dataset.dayLabel || label
      } else {
        break
      }
    }

    setFloatingDay(label)
    setFloatingVisible(true)
    if (floatingHideTimerRef.current) clearTimeout(floatingHideTimerRef.current)
    floatingHideTimerRef.current = setTimeout(() => setFloatingVisible(false), 1000)
  }, [])

  // --- Búsqueda dentro de la conversación ---
  const [searchOpen, setSearchOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState("")
  const [activeMatch, setActiveMatch] = useState(0)
  const searchInputRef = useRef<HTMLInputElement>(null)

  const normalizedQuery = searchQuery.trim()

  // Texto sobre el que se busca en cada mensaje. Debe coincidir con el texto
  // realmente resaltado en el render (plantillas muestran solo el cuerpo).
  const getSearchableText = useCallback((msg: Message): string => {
    if (msg.deleted_at) return ""
    const content = msg.content || ""
    const mediaUrl = msg.media_full_url || msg.media_url

    // El audio solo renderiza el reproductor (nombre de archivo), nunca msg.content:
    // no debe generar coincidencias fantasma.
    if (msg.message_type === "audio" && mediaUrl) return ""

    // Imagen/sticker con media renderizan el caption (msg.content) tal cual.
    // Sin media caen al render de texto genérico, también sobre msg.content.
    if ((msg.message_type === "image" || msg.message_type === "sticker") && mediaUrl) {
      return content
    }

    const parsed = parseTemplateContent(content)
    if (parsed.isTemplate) return parsed.body
    return content
  }, [])

  // Lista ordenada de claves de coincidencia para navegar (prev/siguiente).
  const matchKeys = useMemo(() => {
    if (!normalizedQuery) return [] as string[]
    const lower = normalizedQuery.toLowerCase()
    const keys: string[] = []
    for (const msg of messages) {
      const text = getSearchableText(msg).toLowerCase()
      if (!text) continue
      let from = 0
      let n = 0
      let idx = text.indexOf(lower, from)
      while (idx !== -1) {
        keys.push(`msg-${msg.id}-${n}`)
        n++
        from = idx + lower.length
        idx = text.indexOf(lower, from)
      }
    }
    return keys
  }, [messages, normalizedQuery, getSearchableText])

  const matchCount = matchKeys.length
  const activeMatchKey = matchCount > 0 ? matchKeys[Math.min(activeMatch, matchCount - 1)] : null

  // Reiniciar el índice activo cuando cambia la búsqueda o los resultados.
  useEffect(() => {
    setActiveMatch(0)
  }, [normalizedQuery, matchCount])

  // Enfocar el input al abrir el buscador.
  useEffect(() => {
    if (searchOpen) {
      searchInputRef.current?.focus()
    } else {
      setSearchQuery("")
      setActiveMatch(0)
    }
  }, [searchOpen])

  // Hacer scroll a la coincidencia activa.
  useEffect(() => {
    if (!activeMatchKey || !scrollRef.current) return
    const el = scrollRef.current.querySelector(`[data-match-key="${activeMatchKey}"]`)
    if (el) {
      el.scrollIntoView({ behavior: "smooth", block: "center" })
    }
  }, [activeMatchKey])

  const goToNextMatch = useCallback(() => {
    if (matchCount === 0) return
    setActiveMatch((prev) => (prev + 1) % matchCount)
  }, [matchCount])

  const goToPrevMatch = useCallback(() => {
    if (matchCount === 0) return
    setActiveMatch((prev) => (prev - 1 + matchCount) % matchCount)
  }, [matchCount])

  const handleSearchKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Enter") {
      event.preventDefault()
      if (event.shiftKey) goToPrevMatch()
      else goToNextMatch()
    } else if (event.key === "Escape") {
      event.preventDefault()
      setSearchOpen(false)
    }
  }

  useLayoutEffect(() => {
    if (scrollRef.current && prevScrollHeightRef.current > 0) {
      const newScrollHeight = scrollRef.current.scrollHeight
      const diff = newScrollHeight - prevScrollHeightRef.current
      scrollRef.current.scrollTop = diff
      prevScrollHeightRef.current = 0
    }
  }, [messages])

  useEffect(() => {
    if (messages.length === 0) return
    // No robar el scroll a la coincidencia activa mientras se busca.
    if (searchOpen && normalizedQuery) return

    const lastMsg = messages[messages.length - 1]

    if (lastMsg.id !== lastMessageIdRef.current) {
      if (scrollRef.current) {
        const behavior = lastMessageIdRef.current === null ? "auto" : "smooth"
        scrollRef.current.scrollTo({
          top: scrollRef.current.scrollHeight,
          behavior: behavior
        })
      }
      lastMessageIdRef.current = lastMsg.id
    }
  }, [messages])

  const handleScroll = () => {
    if (!scrollRef.current) return

    const { scrollTop, scrollHeight } = scrollRef.current

    updateFloatingDay()

    if (scrollTop === 0 && !isLoadingMore && hasMore) {
      prevScrollHeightRef.current = scrollHeight
      onLoadMore()
    }
  }

  const canEdit = (msg: Message) =>
    msg.sender_type === "user" &&
    msg.sender_id === currentUserId &&
    msg.direction === "outbound" &&
    (!msg.message_type || msg.message_type === "text") &&
    !msg.deleted_at

  const canDelete = (msg: Message) =>
    !msg.deleted_at &&
    (
      (msg.sender_type === "user" && msg.sender_id === currentUserId) ||
      isAdmin
    )

  const canTranslate = (msg: Message) =>
    !msg.deleted_at &&
    !!msg.content?.trim() &&
    (!msg.message_type || msg.message_type === "text" || msg.message_type === "image")

  const hasActions = (msg: Message) => canEdit(msg) || canDelete(msg) || canTranslate(msg)

  const handleTranslate = async (msg: Message) => {
    const key = String(msg.id)
    const current = translations[key]
    const isCurrent = current?.sourceContent === msg.content && current.targetLanguage === translationLanguage

    if (isCurrent && current.content) {
      setTranslations((prev) => ({
        ...prev,
        [key]: { ...current, visible: !current.visible, error: undefined },
      }))
      return
    }

    setTranslations((prev) => ({
      ...prev,
      [key]: {
        sourceContent: msg.content,
        targetLanguage: translationLanguage,
        visible: true,
        loading: true,
      },
    }))

    try {
      const result = await onTranslateMessage(msg, translationLanguage)
      setTranslations((prev) => ({
        ...prev,
        [key]: {
          sourceContent: msg.content,
          targetLanguage: result.target_language,
          content: result.translated_content,
          visible: true,
          loading: false,
        },
      }))
    } catch (error) {
      setTranslations((prev) => ({
        ...prev,
        [key]: {
          sourceContent: msg.content,
          targetLanguage: translationLanguage,
          visible: true,
          loading: false,
          error: error instanceof Error ? error.message : t("chats.translationError"),
        },
      }))
    }
  }

  return (
    <>
      {/* Contenedor relativo para superponer el buscador sobre los mensajes */}
      <div className="thread-scroll-root relative flex flex-1 min-h-0 flex-col">
        <ThreadBackdrop />
        {/* Botón lupa flotante (cuando el buscador está cerrado) */}
        {!searchOpen && (
          <button
            type="button"
            onClick={() => setSearchOpen(true)}
            className="absolute right-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full border border-border/60 bg-card/90 text-muted-foreground shadow-sm backdrop-blur transition-colors hover:bg-muted hover:text-foreground"
            title={t("chats.searchInChat")}
            aria-label={t("chats.searchInChat")}
          >
            <Search className="h-4 w-4" />
          </button>
        )}

        {/* Barra de búsqueda flotante */}
        {searchOpen && (
          <div className="absolute inset-x-3 top-3 z-10 flex items-center gap-1 rounded-full border border-border bg-card/95 py-1 pl-3 pr-1 shadow-lg backdrop-blur duration-150 animate-in fade-in slide-in-from-top-2">
            <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
            <Input
              ref={searchInputRef}
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={handleSearchKeyDown}
              placeholder={t("chats.searchInChat")}
              className="h-8 flex-1 border-0 bg-transparent px-1 text-sm shadow-none focus-visible:ring-0"
              aria-label={t("chats.searchInChat")}
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => {
                  setSearchQuery("")
                  searchInputRef.current?.focus()
                }}
                className="rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                title={t("chats.searchClear")}
                aria-label={t("chats.searchClear")}
              >
                <X className="h-3.5 w-3.5" />
              </button>
            )}
            <span className="min-w-[3rem] shrink-0 px-1 text-center text-xs tabular-nums text-muted-foreground">
              {normalizedQuery
                ? matchCount > 0
                  ? `${activeMatch + 1}/${matchCount}`
                  : t("chats.searchNoResults")
                : ""}
            </span>
            <div className="mx-0.5 h-5 w-px shrink-0 bg-border" />
            <div className="flex items-center gap-0.5">
              <button
                type="button"
                onClick={goToPrevMatch}
                disabled={matchCount === 0}
                className="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                title={t("chats.searchPrev")}
                aria-label={t("chats.searchPrev")}
              >
                <ChevronUp className="h-4 w-4" />
              </button>
              <button
                type="button"
                onClick={goToNextMatch}
                disabled={matchCount === 0}
                className="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                title={t("chats.searchNext")}
                aria-label={t("chats.searchNext")}
              >
                <ChevronDown className="h-4 w-4" />
              </button>
              <button
                type="button"
                onClick={() => setSearchOpen(false)}
                className="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                title={t("chats.searchClose")}
                aria-label={t("chats.searchClose")}
              >
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>
        )}

        {/* Chip flotante con el día visible durante el scroll */}
        {floatingDay && (
          <div
            aria-hidden={!floatingVisible}
            className={`pointer-events-none absolute left-1/2 z-10 -translate-x-1/2 motion-safe:transition-opacity motion-safe:duration-200 ${
              searchOpen ? "top-16" : "top-3"
            } ${floatingVisible ? "opacity-100" : "opacity-0"}`}
          >
            <span className="rounded-full border border-border/60 bg-card/95 px-3 py-1 text-xs font-medium text-muted-foreground shadow-sm backdrop-blur dark:shadow-none">
              {floatingDay}
            </span>
          </div>
        )}

        <div
          ref={scrollRef}
          className={`thread-scroller relative z-[1] flex-1 p-4 overflow-y-auto min-h-0 transition-[padding] ${searchOpen ? "pt-16" : ""}`}
          onScroll={handleScroll}
        >
        {isLoadingMore && (
          <div className="flex justify-center py-2">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        )}

        <div className="space-y-4">
          {messages.map((msg, index) => {
            const msgTimestamp = msg.delivered_at || msg.created_at
            const msgDate = msgTimestamp ? new Date(msgTimestamp) : null
            const prevMsg = index > 0 ? messages[index - 1] : null
            const prevTimestamp = prevMsg ? prevMsg.delivered_at || prevMsg.created_at : null
            const prevDate = prevTimestamp ? new Date(prevTimestamp) : null
            const dayLabel =
              msgDate && !isNaN(msgDate.getTime()) && (!prevDate || !isSameDay(msgDate, prevDate))
                ? getDayLabel(msgDate, language, t)
                : null

            if (channelType === ChannelType.MAIL) {
              return (
                <Fragment key={msg.id}>
                  {dayLabel && (
                    <div data-day-label={dayLabel} className="flex justify-center">
                      <span className="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground dark:bg-card">
                        {dayLabel}
                      </span>
                    </div>
                  )}
                  <MailMessageBlock message={msg} />
                </Fragment>
              )
            }

            const isOwn = msg.sender_type === "user" || (msg.sender_type === "system" && msg.direction === "outbound")
            const messageGroupKey = getMessageGroupKey(msg)
            const prevMessageGroupKey = prevMsg ? getMessageGroupKey(prevMsg) : null
            const nextMsg = index < messages.length - 1 ? messages[index + 1] : null
            const nextMessageGroupKey = nextMsg ? getMessageGroupKey(nextMsg) : null
            const nextTimestamp = nextMsg ? nextMsg.delivered_at || nextMsg.created_at : null
            const nextDate = nextTimestamp ? new Date(nextTimestamp) : null

            // Agrupamos mensajes consecutivos del mismo emisor dentro de una
            // ventana corta: sólo el último de la tanda lleva cola y hora.
            const withinWindow = (a: Date | null, b: Date | null) =>
              !!a && !!b && Math.abs(a.getTime() - b.getTime()) < GROUPING_WINDOW_MS

            const isFirstOfGroup =
              !!dayLabel || prevMessageGroupKey !== messageGroupKey || !withinWindow(msgDate, prevDate)
            const isLastOfGroup =
              !nextMsg ||
              nextMessageGroupKey !== messageGroupKey ||
              !withinWindow(msgDate, nextDate) ||
              (!!nextDate && !!msgDate && !isSameDay(msgDate, nextDate))

            return (
              <Fragment key={msg.id}>
                {dayLabel && (
                  <div data-day-label={dayLabel} className="flex justify-center py-2">
                    <span className="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground dark:bg-card">
                      {dayLabel}
                    </span>
                  </div>
                )}
                <MessageBubble
                  message={msg}
                  isFirstOfGroup={isFirstOfGroup}
                  isLastOfGroup={isLastOfGroup}
                  translationLanguage={translationLanguage}
                  translationState={translations[String(msg.id)]}
                  normalizedQuery={normalizedQuery}
                  activeMatchKey={activeMatchKey}
                  onTranslate={(target) => void handleTranslate(target)}
                  onEdit={onEditMessage}
                  onDelete={(target) => setDeleteTarget(target)}
                  canEdit={!!canEdit(msg) && !!onEditMessage}
                  canDelete={!!canDelete(msg) && !!onDeleteMessage}
                  canTranslate={!!canTranslate(msg)}
                />
              </Fragment>
            )
          })}
          </div>
        </div>
      </div>

      {/* Delete confirmation dialog */}
      <AlertDialog open={!!deleteTarget} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("chats.deleteMessageTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("chats.deleteMessageConfirm")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (deleteTarget && onDeleteMessage) {
                  onDeleteMessage(deleteTarget)
                }
                setDeleteTarget(null)
              }}
            >
              {t("chats.deleteMessageAction")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
