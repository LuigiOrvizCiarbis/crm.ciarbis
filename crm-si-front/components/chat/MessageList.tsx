import { Message, TranslationLanguage } from "@/data/types"
import { MessageBubble } from "./MessageBubble"
import { getDayLabel, isSameDay, parseTemplateContent } from "./messageThreadUtils"
import { ThreadBackdrop } from "./ThreadBackdrop"
import { ChannelType } from "@/data/enums"
import { Fragment, memo, useEffect, useRef, useLayoutEffect, useState, useMemo, useCallback } from "react"
import { Loader2, Search, X, ChevronUp, ChevronDown, RefreshCw } from "lucide-react"
import type { MessageTranslationResponse } from "@/lib/api/messages"
import { useTranslation } from "@/hooks/useTranslation"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
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

/** Páginas viejas que se cargan como máximo buscando el original de una cita. */
const MAX_QUOTE_JUMP_PAGES = 10
const QUOTE_HIGHLIGHT_MS = 1600
/** Tope de frames esperando que termine una carga en curso (~10 s a 60 fps). */
const MAX_LOAD_WAIT_FRAMES = 600

/** Espera a que React pinte: dos rAF garantizan que el DOM ya refleja el render. */
const nextFrame = () =>
  new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))

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
  /** La última carga de mensajes anteriores falló: se ofrece reintento manual. */
  loadMoreFailed?: boolean
  onEditMessage?: (message: Message) => void
  onDeleteMessage?: (message: Message) => void
  onReactMessage?: (message: Message, emoji: string) => void
  onReplyMessage?: (message: Message) => void
  /** Nombre del contacto, para el autor de las citas entrantes. */
  contactName?: string | null
  onSaveContact?: (message: Message, index: number) => void
  currentUserId?: number
  isAdmin?: boolean
  /** conversations.send_message: sin esto, reaccionar comería un 403 del back. */
  canSendMessage?: boolean
  translationLanguage: TranslationLanguage
  onTranslateMessage: (message: Message, targetLanguage: TranslationLanguage) => Promise<MessageTranslationResponse>
  channelType?: ChannelType
  isGroupConversation?: boolean
  /** El buscador se abre desde el header: una lupa flotando sobre el hilo tapaba burbujas. */
  searchOpen: boolean
  onSearchOpenChange: (open: boolean) => void
}

interface TranslationState {
  content?: string
  sourceContent: string
  targetLanguage: TranslationLanguage
  visible: boolean
  loading: boolean
  error?: string
}

/**
 * Chip flotante con el día visible (estilo WhatsApp). Tiene su propio estado para que
 * scrollear no re-renderice el hilo: el día sale de un IntersectionObserver sobre los
 * separadores (sólo avisa cuando uno cruza el borde superior) y la visibilidad, de un
 * listener pasivo de scroll. Ninguno lee layout por evento.
 */
function FloatingDayChip({
  scrollRef,
  messages,
  language,
  raised,
}: {
  scrollRef: React.RefObject<HTMLDivElement | null>
  /** Sólo como dependencia: los separadores cambian con los mensajes. */
  messages: Message[]
  language: string
  raised: boolean
}) {
  const [day, setDay] = useState("")
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const root = scrollRef.current
    if (!root) return
    const separators = Array.from(root.querySelectorAll<HTMLElement>("[data-day-label]"))
    if (separators.length < 2) {
      setDay("")
      return
    }
    const above = new Set<Element>()
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const rootTop = entry.rootBounds?.top ?? 0
          if (entry.boundingClientRect.top < rootTop) above.add(entry.target)
          else above.delete(entry.target)
        }
        // Sin separador por encima, el del día está a la vista: repetirlo flotando lo duplicaba.
        let label = ""
        for (const separator of separators) {
          if (!above.has(separator)) break
          label = separator.dataset.dayLabel || label
        }
        setDay(label)
      },
      { root, rootMargin: "-12px 0px 0px 0px", threshold: [0, 1] },
    )
    separators.forEach((separator) => observer.observe(separator))
    return () => observer.disconnect()
  }, [scrollRef, messages, language])

  // Visible mientras dura el scroll; se desvanece ~1s después de parar.
  useEffect(() => {
    const root = scrollRef.current
    if (!root) return
    let shown = false
    let hideTimer: ReturnType<typeof setTimeout> | undefined
    const onScroll = () => {
      if (!shown) {
        shown = true
        setVisible(true)
      }
      clearTimeout(hideTimer)
      hideTimer = setTimeout(() => {
        shown = false
        setVisible(false)
      }, 1000)
    }
    root.addEventListener("scroll", onScroll, { passive: true })
    return () => {
      root.removeEventListener("scroll", onScroll)
      clearTimeout(hideTimer)
    }
  }, [scrollRef])

  if (!day) return null
  return (
    <div
      aria-hidden={!visible}
      className={`pointer-events-none absolute left-1/2 z-10 -translate-x-1/2 motion-safe:transition-opacity motion-safe:duration-200 ${
        raised ? "top-16" : "top-3"
      } ${visible ? "opacity-100" : "opacity-0"}`}
    >
      <span className="rounded-full border border-border/60 bg-card/95 px-3 py-1 text-xs font-medium text-muted-foreground shadow-sm backdrop-blur">
        {day}
      </span>
    </div>
  )
}

/**
 * Memoizado: la página re-renderiza por eventos que no tocan el hilo (lista de
 * conversaciones, tiempo real de otros chats). Con props estables, el hilo no se repinta.
 */
export const MessageList = memo(function MessageList({
  messages,
  onLoadMore,
  hasMore,
  isLoadingMore,
  loadMoreFailed = false,
  onEditMessage,
  onDeleteMessage,
  onReactMessage,
  onReplyMessage,
  contactName,
  onSaveContact,
  currentUserId,
  isAdmin,
  canSendMessage,
  translationLanguage,
  onTranslateMessage,
  channelType,
  isGroupConversation = false,
  searchOpen,
  onSearchOpenChange,
}: MessageListProps) {
  const { t, language } = useTranslation()
  const { addToast } = useToast()
  const scrollRef = useRef<HTMLDivElement>(null)
  // Posición previa a cargar mensajes viejos, para mantener el ancla visual al anteponerlos.
  const prependAnchorRef = useRef<{ height: number; top: number } | null>(null)
  const lastMessageIdRef = useRef<number | null>(null)
  const topSentinelRef = useRef<HTMLDivElement>(null)
  const [deleteTarget, setDeleteTarget] = useState<Message | null>(null)
  const [translations, setTranslations] = useState<Record<string, TranslationState>>({})
  // Lectura sin dependencia: handleTranslate queda estable y las burbujas memoizadas no se invalidan.
  const translationsRef = useRef(translations)
  const onLoadMoreRef = useRef(onLoadMore)
  const hasMoreRef = useRef(hasMore)
  const isLoadingMoreRef = useRef(isLoadingMore)
  const loadMoreFailedRef = useRef(loadMoreFailed)
  useLayoutEffect(() => {
    translationsRef.current = translations
    onLoadMoreRef.current = onLoadMore
    hasMoreRef.current = hasMore
    isLoadingMoreRef.current = isLoadingMore
    loadMoreFailedRef.current = loadMoreFailed
  })

  // --- Saltar al mensaje citado ---
  const [highlightedMessageId, setHighlightedMessageId] = useState<number | null>(null)
  const highlightTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const jumpingRef = useRef(false)
  // El hilo se monta por conversación: un salto en curso no debe seguir
  // paginando (con un onLoadMore viejo) después de cambiar de chat.
  const isMountedRef = useRef(true)
  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
      clearTimeout(highlightTimeoutRef.current ?? undefined)
    }
  }, [])

  const revealMessage = useCallback((id: number) => {
    const el = scrollRef.current?.querySelector<HTMLElement>(`[data-message-id="${id}"]`)
    if (!el) return false
    el.scrollIntoView({ behavior: "smooth", block: "center" })
    setHighlightedMessageId(id)
    clearTimeout(highlightTimeoutRef.current ?? undefined)
    highlightTimeoutRef.current = setTimeout(() => setHighlightedMessageId(null), QUOTE_HIGHLIGHT_MS)
    return true
  }, [])

  const handleJumpToMessage = useCallback(
    async (id: number) => {
      if (revealMessage(id) || jumpingRef.current) return
      jumpingRef.current = true
      try {
        for (let page = 0; page < MAX_QUOTE_JUMP_PAGES; page++) {
          for (let frames = 0; isLoadingMoreRef.current && frames < MAX_LOAD_WAIT_FRAMES; frames++) {
            await nextFrame()
          }
          if (!isMountedRef.current || !hasMoreRef.current || loadMoreFailedRef.current) break
          const root = scrollRef.current
          if (root) prependAnchorRef.current = { height: root.scrollHeight, top: root.scrollTop }
          await onLoadMoreRef.current()
          await nextFrame()
          if (revealMessage(id)) return
        }
        if (isMountedRef.current) addToast({ type: "info", title: t("chats.quotedMessageNotFound") })
      } finally {
        jumpingRef.current = false
      }
    },
    [revealMessage, addToast, t],
  )
  // Anuncio para lectores de pantalla: sólo mensajes entrantes nuevos al final del hilo.
  const [arrivalAnnouncement, setArrivalAnnouncement] = useState("")

  // --- Búsqueda dentro de la conversación ---
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

  // Claves de coincidencia ordenadas (prev/siguiente) y los mensajes que coinciden:
  // sólo esas burbujas reciben la búsqueda, el resto conserva props y no re-renderiza.
  const { matchKeys, matchedIds } = useMemo(() => {
    const keys: string[] = []
    const ids = new Set<number>()
    if (!normalizedQuery) return { matchKeys: keys, matchedIds: ids }
    const lower = normalizedQuery.toLowerCase()
    for (const msg of messages) {
      const text = getSearchableText(msg).toLowerCase()
      if (!text) continue
      let from = 0
      let n = 0
      let idx = text.indexOf(lower, from)
      while (idx !== -1) {
        keys.push(`msg-${msg.id}-${n}`)
        ids.add(msg.id)
        n++
        from = idx + lower.length
        idx = text.indexOf(lower, from)
      }
    }
    return { matchKeys: keys, matchedIds: ids }
  }, [messages, normalizedQuery, getSearchableText])

  const matchCount = matchKeys.length
  const activeMatchKey = matchCount > 0 ? matchKeys[Math.min(activeMatch, matchCount - 1)] : null
  const activeMatchMessageId = activeMatchKey ? Number(activeMatchKey.split("-")[1]) : null

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
      onSearchOpenChange(false)
    }
  }

  // Al anteponer mensajes viejos, conservar lo que el usuario estaba viendo.
  useLayoutEffect(() => {
    const root = scrollRef.current
    const anchor = prependAnchorRef.current
    if (!root || !anchor) return
    root.scrollTop = root.scrollHeight - anchor.height + anchor.top
    prependAnchorRef.current = null
  }, [messages])

  useEffect(() => {
    if (messages.length === 0) return
    // No robar el scroll a la coincidencia activa mientras se busca.
    if (searchOpen && normalizedQuery) return

    const lastMsg = messages[messages.length - 1]

    if (lastMsg.id !== lastMessageIdRef.current) {
      const isFirstPaint = lastMessageIdRef.current === null
      if (scrollRef.current) {
        const behavior = isFirstPaint ? "auto" : "smooth"
        scrollRef.current.scrollTo({
          top: scrollRef.current.scrollHeight,
          behavior: behavior
        })
      }
      if (!isFirstPaint && lastMsg.direction === "inbound") {
        const preview = lastMsg.content?.trim().slice(0, 160) ?? ""
        const name = lastMsg.sender?.name
        setArrivalAnnouncement(
          name
            ? t("chats.newMessageFromAnnouncement", { name, text: preview })
            : t("chats.newMessageAnnouncement", { text: preview }),
        )
      }
      lastMessageIdRef.current = lastMsg.id
    }
  }, [messages])

  // Paginación hacia atrás: un centinela arriba del hilo dispara la carga al acercarse
  // (300px antes del tope), en lugar de leer scrollTop/scrollHeight en cada evento.
  useEffect(() => {
    const root = scrollRef.current
    const sentinel = topSentinelRef.current
    if (!root || !sentinel || !hasMore || isLoadingMore || loadMoreFailed) return
    const observer = new IntersectionObserver(
      ([entry]) => {
        // Al montar el hilo arranca arriba: esperar al primer scroll al final.
        if (!entry.isIntersecting || lastMessageIdRef.current === null) return
        observer.disconnect()
        prependAnchorRef.current = { height: root.scrollHeight, top: root.scrollTop }
        void onLoadMoreRef.current()
      },
      { root, rootMargin: "300px 0px 0px 0px" },
    )
    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [hasMore, isLoadingMore, loadMoreFailed])

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

  /**
   * Reaccionar es exclusivo de WhatsApp: Instagram y Messenger dejaron los
   * eventos de reacción fuera de alcance, y Mail no aplica. Además hace
   * falta el wamid (external_id): sin él, Meta no sabe a qué mensaje
   * reaccionar. canSendMessage evita ofrecer una acción que el back
   * rechazaría con 403.
   */
  const canReact = (msg: Message) =>
    channelType === ChannelType.WHATSAPP &&
    !msg.deleted_at &&
    !!msg.external_id &&
    !!canSendMessage

  /** Citar requiere el id del canal (no optimistas) y un canal Meta con soporte de reply. */
  const canReply = (msg: Message) =>
    (channelType === ChannelType.WHATSAPP ||
      channelType === ChannelType.INSTAGRAM ||
      channelType === ChannelType.FACEBOOK) &&
    !msg.deleted_at &&
    !msg.failed_at &&
    !!msg.external_id &&
    !!canSendMessage

  const hasActions = (msg: Message) =>
    canEdit(msg) || canDelete(msg) || canTranslate(msg) || canReact(msg) || canReply(msg)

  const handleTranslate = useCallback(async (msg: Message) => {
    const key = String(msg.id)
    const current = translationsRef.current[key]
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
  }, [onTranslateMessage, t, translationLanguage])

  const translateMessage = useCallback((msg: Message) => void handleTranslate(msg), [handleTranslate])

  return (
    <>
      {/* Contenedor relativo para superponer el buscador sobre los mensajes */}
      <div className="thread-scroll-root relative flex flex-1 min-h-0 flex-col">
        <ThreadBackdrop />

        {/* Barra de búsqueda flotante */}
        {searchOpen && (
          <div className="absolute inset-x-3 top-3 z-10 flex items-center gap-1 rounded-full border border-border bg-card/95 py-1 pl-3 pr-1 shadow-lg backdrop-blur duration-150 animate-in fade-in slide-in-from-top-2 has-[input:focus-visible]:border-ring has-[input:focus-visible]:ring-[3px] has-[input:focus-visible]:ring-ring/50">
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
                onClick={() => onSearchOpenChange(false)}
                className="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                title={t("chats.searchClose")}
                aria-label={t("chats.searchClose")}
              >
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>
        )}

        <FloatingDayChip scrollRef={scrollRef} messages={messages} language={language} raised={searchOpen} />

        <div
          ref={scrollRef}
          className={`thread-scroller relative z-[1] flex-1 p-4 overflow-y-auto min-h-0 transition-[padding] ${searchOpen ? "pt-16" : ""}`}
        >
        <div ref={topSentinelRef} aria-hidden="true" className="h-px" />
        {isLoadingMore && (
          <div role="status" aria-label={t("chats.loading")} className="flex justify-center py-2">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" aria-hidden="true" />
          </div>
        )}
        {loadMoreFailed && !isLoadingMore && hasMore && (
          <div className="flex items-center justify-center gap-2 py-2 text-xs text-muted-foreground">
            <span>{t("chats.loadHistoryError")}</span>
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1.5 px-2 text-xs"
              onClick={() => {
                const root = scrollRef.current
                if (root) prependAnchorRef.current = { height: root.scrollHeight, top: root.scrollTop }
                void onLoadMore()
              }}
            >
              <RefreshCw className="h-3 w-3" aria-hidden="true" />
              {t("chats.retry")}
            </Button>
          </div>
        )}

        <div className="space-y-4" role="log" aria-live="off" aria-label={t("chats.threadLabel")}>
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
                  normalizedQuery={matchedIds.has(msg.id) ? normalizedQuery : ""}
                  activeMatchKey={activeMatchMessageId === msg.id ? activeMatchKey : null}
                  onTranslate={translateMessage}
                  onEdit={onEditMessage}
                  onDelete={setDeleteTarget}
                  onReact={onReactMessage}
                  onSaveContact={onSaveContact}
                  canEdit={!!canEdit(msg) && !!onEditMessage}
                  canDelete={!!canDelete(msg) && !!onDeleteMessage}
                  canTranslate={!!canTranslate(msg)}
                  canReact={canReact(msg) && !!onReactMessage}
                  canReply={canReply(msg) && !!onReplyMessage}
                  onReply={onReplyMessage}
                  onJumpToMessage={handleJumpToMessage}
                  isHighlighted={highlightedMessageId === msg.id}
                  contactName={contactName}
                  currentUserId={currentUserId}
                  isGroupConversation={isGroupConversation}
                />
              </Fragment>
            )
          })}
          </div>
        </div>
      </div>
      {/* El log no anuncia por sí mismo (leería todo el historial al cargar o paginar);
          este estado anuncia sólo lo que llega en vivo. */}
      <p role="status" className="sr-only">
        {arrivalAnnouncement}
      </p>

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
})
