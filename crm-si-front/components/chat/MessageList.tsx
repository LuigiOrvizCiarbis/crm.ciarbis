import { Message, TranslationLanguage } from "@/data/types"
import { ChannelType } from "@/data/enums"
import { Fragment, useEffect, useRef, useLayoutEffect, useState, useMemo, useCallback } from "react"
import { Loader2, MoreHorizontal, Pencil, Trash2, Music2, Search, X, ChevronUp, ChevronDown, Bot, Languages, EyeOff, RefreshCw, AlertCircle } from "lucide-react"
import type { MessageTranslationResponse } from "@/lib/api/messages"
import { useTranslation } from "@/hooks/useTranslation"
import { pauseOtherAudios } from "@/lib/audio"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
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
import { Button } from "@/components/ui/button"
import { MailMessageBlock } from "./MailMessageBlock"

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

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
}

/**
 * Resalta las coincidencias de `query` dentro de `text`.
 * Marca la coincidencia activa con un color distinto para la navegación.
 */
function highlightText(
  text: string,
  query: string,
  activeKey: string | null,
  matchKeyPrefix: string,
): React.ReactNode {
  if (!query) return text
  const regex = new RegExp(`(${escapeRegExp(query)})`, "gi")
  const parts = text.split(regex)
  let matchIndex = 0
  return parts.map((part, i) => {
    if (part.toLowerCase() === query.toLowerCase()) {
      const key = `${matchKeyPrefix}-${matchIndex++}`
      const isActive = key === activeKey
      return (
        <mark
          key={i}
          data-match-key={key}
          className={
            isActive
              ? "rounded bg-orange-400 px-0.5 text-black"
              : "rounded bg-yellow-300/70 px-0.5 text-black"
          }
        >
          {part}
        </mark>
      )
    }
    return part
  })
}

function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  )
}

/**
 * Etiqueta de día estilo WhatsApp: "Hoy", "Ayer", día de la semana si es de
 * los últimos 7 días, y fecha completa localizada para lo anterior.
 */
function getDayLabel(
  date: Date,
  language: string,
  t: (key: string) => string,
): string {
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const diffDays = Math.round(
    (startOfDay(new Date()).getTime() - startOfDay(date).getTime()) / 86400000,
  )
  if (diffDays === 0) return t("chats.dateToday")
  if (diffDays === 1) return t("chats.dateYesterday")
  if (diffDays > 1 && diffDays < 7) {
    const weekday = date.toLocaleDateString(language, { weekday: "long" })
    return weekday.charAt(0).toUpperCase() + weekday.slice(1)
  }
  return date.toLocaleDateString(language, {
    day: "numeric",
    month: "long",
    year: "numeric",
  })
}

function parseTemplateContent(content: string): { isTemplate: boolean; title: string; body: string } {
  const trimmed = (content || "").trim()
  if (!trimmed) return { isTemplate: false, title: "", body: "" }
  const withoutLeadingIcons = trimmed.replace(/^[^A-Za-z0-9]+/, "").trim()

  if (trimmed.startsWith("📋")) {
    const withoutIcon = trimmed.replace(/^📋\s*/, "")
    if (withoutIcon.includes("\n")) {
      const [firstLine, ...rest] = withoutIcon.split("\n")
      return {
        isTemplate: true,
        title: `📋 ${firstLine.trim()}`,
        body: rest.join("\n").trim(),
      }
    }

    const legacyWithBody = withoutIcon.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)\)$/i)
    if (legacyWithBody) {
      return {
        isTemplate: true,
        title: `📋 ${legacyWithBody[1].trim()}`,
        body: legacyWithBody[2].trim(),
      }
    }

    const legacyWithBodyNoClose = withoutIcon.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)$/i)
    if (legacyWithBodyNoClose) {
      return {
        isTemplate: true,
        title: `📋 ${legacyWithBodyNoClose[1].trim()}`,
        body: legacyWithBodyNoClose[2].trim(),
      }
    }

    const legacyNameOnly = withoutIcon.match(/^Template:\s*([\s\S]+)$/i)
    if (legacyNameOnly) {
      return {
        isTemplate: true,
        title: `📋 ${legacyNameOnly[1].trim()}`,
        body: "",
      }
    }

    return {
      isTemplate: true,
      title: `📋 ${withoutIcon.trim()}`,
      body: "",
    }
  }

  const plainLegacy = withoutLeadingIcons.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)\)$/i)
  if (plainLegacy) {
    return {
      isTemplate: true,
      title: `📋 ${plainLegacy[1].trim()}`,
      body: plainLegacy[2].trim(),
    }
  }

  const plainLegacyNoClose = withoutLeadingIcons.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)$/i)
  if (plainLegacyNoClose) {
    return {
      isTemplate: true,
      title: `📋 ${plainLegacyNoClose[1].trim()}`,
      body: plainLegacyNoClose[2].trim(),
    }
  }

  const plainNameOnly = withoutLeadingIcons.match(/^Template:\s*([\s\S]+)$/i)
  if (plainNameOnly) {
    return {
      isTemplate: true,
      title: `📋 ${plainNameOnly[1].trim()}`,
      body: "",
    }
  }

  return { isTemplate: false, title: "", body: "" }
}

function ImageLightbox({ src, onClose }: { src: string; onClose: () => void }) {
  return (
    <div
      className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center cursor-pointer"
      onClick={onClose}
    >
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={src}
        alt="Imagen completa"
        className="max-w-[90vw] max-h-[90vh] object-contain rounded-lg"
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  )
}

function MessageBubbleImage({ mediaUrl, isUser }: { mediaUrl: string; isUser: boolean }) {
  const [lightboxOpen, setLightboxOpen] = useState(false)

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={mediaUrl}
        alt="Imagen"
        className={`rounded-lg max-w-[240px] max-h-[240px] object-cover cursor-pointer hover:opacity-90 transition-opacity ${
          isUser ? "bg-primary/20" : "bg-muted"
        }`}
        onClick={() => setLightboxOpen(true)}
        loading="lazy"
      />
      {lightboxOpen && <ImageLightbox src={mediaUrl} onClose={() => setLightboxOpen(false)} />}
    </>
  )
}

function MessageBubbleSticker({ mediaUrl }: { mediaUrl: string }) {
  const [lightboxOpen, setLightboxOpen] = useState(false)

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={mediaUrl}
        alt="Sticker"
        className="max-w-[160px] max-h-[160px] object-contain cursor-pointer hover:opacity-90 transition-opacity"
        onClick={() => setLightboxOpen(true)}
        loading="lazy"
      />
      {lightboxOpen && <ImageLightbox src={mediaUrl} onClose={() => setLightboxOpen(false)} />}
    </>
  )
}

function MessageBubbleAudio({ mediaUrl, filename }: { mediaUrl: string; filename?: string | null }) {
  return (
    <div className="space-y-2 min-w-[220px]">
      <div className="flex items-center gap-2 text-xs opacity-80">
        <Music2 className="h-4 w-4 shrink-0" />
        <span className="truncate">{filename || "Audio"}</span>
      </div>
      <audio controls src={mediaUrl} className="w-full max-w-[280px]" preload="metadata" onPlay={pauseOtherAudios} />
    </div>
  )
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
      <div className="relative flex flex-1 min-h-0 flex-col">
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
          className={`flex-1 p-4 overflow-y-auto min-h-0 transition-[padding] ${searchOpen ? "pt-16" : ""}`}
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

            const isUser = msg.sender_type === "user"
            const isBot = msg.sender_type === "system" && msg.direction === "outbound"
            const isDeleted = !!msg.deleted_at
            const isEdited = !!msg.edited_at && !isDeleted
            const hasOriginalContent =
              isEdited &&
              !!msg.original_content &&
              msg.original_content !== msg.content
            const imageUrl = msg.media_full_url || msg.media_url
            const stickerUrl = msg.media_full_url || msg.media_url
            const audioUrl = msg.media_full_url || msg.media_url
            const isImage = msg.message_type === "image" && imageUrl
            const isSticker = msg.message_type === "sticker" && stickerUrl
            const isAudio = msg.message_type === "audio" && audioUrl
            const parsed = !isImage && !isSticker && !isAudio && !isDeleted
              ? parseTemplateContent(msg.content || "")
              : { isTemplate: false, title: "", body: "" }
            const matchKeyPrefix = `msg-${msg.id}`
            const translationState = translations[String(msg.id)]
            const isCurrentTranslation = translationState?.sourceContent === msg.content
              && translationState.targetLanguage === translationLanguage

            return (
              <Fragment key={msg.id}>
                {dayLabel && (
                  <div data-day-label={dayLabel} className="flex justify-center">
                    <span className="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground dark:bg-card">
                      {dayLabel}
                    </span>
                  </div>
                )}
              <div
                className={`group/msg flex ${isUser || isBot ? "justify-end" : "justify-start"}`}
              >
                {/* Action button - before bubble for user messages */}
                {isUser && hasActions(msg) && (
                  <div className="flex items-center mr-1 opacity-0 group-hover/msg:opacity-100 transition-opacity">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-7 w-7">
                          <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        {canTranslate(msg) && (
                          <DropdownMenuItem
                            onClick={() => void handleTranslate(msg)}
                            disabled={isCurrentTranslation && translationState.loading}
                          >
                            {isCurrentTranslation && translationState.visible ? (
                              <EyeOff className="h-4 w-4 mr-2" />
                            ) : (
                              <Languages className="h-4 w-4 mr-2" />
                            )}
                            {isCurrentTranslation && translationState.visible
                              ? t("chats.hideTranslation")
                              : isCurrentTranslation && translationState.content
                                ? t("chats.showTranslation")
                                : t("chats.translateMessage")}
                          </DropdownMenuItem>
                        )}
                        {canEdit(msg) && onEditMessage && (
                          <DropdownMenuItem onClick={() => onEditMessage(msg)}>
                            <Pencil className="h-4 w-4 mr-2" />
                            {t("chats.editMessage")}
                          </DropdownMenuItem>
                        )}
                        {canDelete(msg) && onDeleteMessage && (
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            onClick={() => setDeleteTarget(msg)}
                          >
                            <Trash2 className="h-4 w-4 mr-2" />
                            {t("chats.deleteMessage")}
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                )}

                <div
                  className={`p-3 rounded-lg max-w-xs break-words overflow-hidden ${isUser
                      ? "bg-primary text-primary-foreground"
                      : isBot
                        ? "border border-primary/30 bg-primary/10"
                        : "bg-muted"
                    }`}
                >
                  {isBot && (
                    <div className="mb-1 flex items-center gap-1 text-[11px] font-medium text-primary">
                      <Bot className="h-3 w-3" />
                      {t("chats.aiBadge")}
                    </div>
                  )}
                  {isDeleted ? (
                    <p className="text-sm italic opacity-60">
                      {t("chats.messageDeleted")}
                    </p>
                  ) : hasOriginalContent ? (
                    <div className="space-y-2">
                      <p className="text-xs opacity-70 line-through whitespace-pre-wrap [overflow-wrap:anywhere]">
                        {msg.original_content}
                      </p>
                      <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
                        {normalizedQuery
                          ? highlightText(msg.content || "", normalizedQuery, activeMatchKey, matchKeyPrefix)
                          : msg.content}
                      </p>
                    </div>
                  ) : isImage && imageUrl ? (
                    <div className="space-y-1">
                      <MessageBubbleImage mediaUrl={imageUrl} isUser={isUser} />
                      {msg.content && (
                        <p className="text-sm mt-1">
                          {normalizedQuery
                            ? highlightText(msg.content, normalizedQuery, activeMatchKey, matchKeyPrefix)
                            : msg.content}
                        </p>
                      )}
                    </div>
                  ) : isSticker && stickerUrl ? (
                    <div className="space-y-1">
                      <MessageBubbleSticker mediaUrl={stickerUrl} />
                      {msg.content && (
                        <p className="text-sm mt-1">
                          {normalizedQuery
                            ? highlightText(msg.content, normalizedQuery, activeMatchKey, matchKeyPrefix)
                            : msg.content}
                        </p>
                      )}
                    </div>
                  ) : isAudio && audioUrl ? (
                    <MessageBubbleAudio mediaUrl={audioUrl} filename={msg.media_filename} />
                  ) : parsed.isTemplate ? (
                    <div className="space-y-1">
                      <span className="text-xs font-medium opacity-75">
                        {parsed.title}
                      </span>
                      {parsed.body && (
                        <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
                          {normalizedQuery
                            ? highlightText(parsed.body, normalizedQuery, activeMatchKey, matchKeyPrefix)
                            : parsed.body}
                        </p>
                      )}
                    </div>
                  ) : (
                    <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
                      {normalizedQuery
                        ? highlightText(msg.content || "", normalizedQuery, activeMatchKey, matchKeyPrefix)
                        : msg.content}
                    </p>
                  )}
                  {(() => {
                    const translation = translationState
                    if (!translation?.visible || !isCurrentTranslation) return null

                    return (
                      <div
                        className="mt-2 border-t border-current/15 pt-2"
                        aria-live="polite"
                      >
                        <div className="mb-1 flex items-center justify-between gap-3 text-[11px] font-medium opacity-70">
                          <span className="inline-flex items-center gap-1">
                            <Languages className="h-3 w-3" />
                            {t("chats.translationTo", { language: t(`chats.language.${translationLanguage}`) })}
                          </span>
                          {!translation.loading && !translation.error && (
                            <button
                              type="button"
                              className="rounded-sm underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                              onClick={() => void handleTranslate(msg)}
                            >
                              {t("chats.hideTranslation")}
                            </button>
                          )}
                        </div>
                        {translation.loading ? (
                          <div className="space-y-1.5 py-1" aria-label={t("chats.translating") }>
                            <div className="h-3 w-full animate-pulse rounded-sm bg-current/10 motion-reduce:animate-none" />
                            <div className="h-3 w-2/3 animate-pulse rounded-sm bg-current/10 motion-reduce:animate-none" />
                          </div>
                        ) : translation.error ? (
                          <div className="flex items-start justify-between gap-2 text-xs">
                            <span className="opacity-80">{translation.error}</span>
                            <button
                              type="button"
                              className="inline-flex shrink-0 items-center gap-1 rounded-sm font-medium underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                              onClick={() => void handleTranslate(msg)}
                            >
                              <RefreshCw className="h-3 w-3" />
                              {t("chats.retry")}
                            </button>
                          </div>
                        ) : (
                          <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
                            {translation.content}
                          </p>
                        )}
                      </div>
                    )
                  })()}
                  {(msg.delivered_at || msg.created_at) && (
                    <div className="flex items-center gap-1">
                      <span className="text-xs text-muted-foreground">
                        {new Date(msg.delivered_at || msg.created_at).toLocaleTimeString([], {
                          hour: "2-digit",
                          minute: "2-digit",
                          hour12: true,
                        })}
                      </span>
                      {isEdited && (
                        <span className="text-xs text-muted-foreground opacity-70">
                          · {t("chats.edited")}
                        </span>
                      )}
                      {msg.direction === "outbound" && msg.failed_at && (
                        <span
                          className="flex items-center gap-0.5 text-xs text-destructive"
                          title={msg.error_message ?? undefined}
                        >
                          <AlertCircle className="h-3 w-3" />
                          {t("chats.messageFailed")}
                        </span>
                      )}
                    </div>
                  )}
                </div>

                {/* Action button - after bubble for contact messages */}
                {!isUser && hasActions(msg) && (
                  <div className="flex items-center ml-1 opacity-0 group-hover/msg:opacity-100 transition-opacity">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-7 w-7">
                          <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="start">
                        {canTranslate(msg) && (
                          <DropdownMenuItem
                            onClick={() => void handleTranslate(msg)}
                            disabled={isCurrentTranslation && translationState.loading}
                          >
                            {isCurrentTranslation && translationState.visible ? (
                              <EyeOff className="h-4 w-4 mr-2" />
                            ) : (
                              <Languages className="h-4 w-4 mr-2" />
                            )}
                            {isCurrentTranslation && translationState.visible
                              ? t("chats.hideTranslation")
                              : isCurrentTranslation && translationState.content
                                ? t("chats.showTranslation")
                                : t("chats.translateMessage")}
                          </DropdownMenuItem>
                        )}
                        {canDelete(msg) && onDeleteMessage && (
                          <DropdownMenuItem
                            className="text-destructive focus:text-destructive"
                            onClick={() => setDeleteTarget(msg)}
                          >
                            <Trash2 className="h-4 w-4 mr-2" />
                            {t("chats.deleteMessage")}
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                )}
              </div>
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
