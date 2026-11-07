import { memo, useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react"
import { Card } from "@/components/ui/card"
import { ContactAvatar } from "@/components/contact-avatar"
import { Badge } from "@/components/ui/badge"
import { LeadScoreBadge } from "@/components/Badges"
import { PlatformIcon } from "@/components/chat/PlatformIcon"
import { EmptyState } from "@/components/EmptyState"
import { SkeletonList } from "@/components/Skeleton"
import { AlertCircle, Bot, Check, Loader2, MessageSquare, Users } from "lucide-react"
import { Conversation, Channel } from "@/data/types"
import { channelTypeToFilterType } from "@/data/enums"
import { useTranslation } from "@/hooks/useTranslation"
import { TagChips } from "@/components/tags/TagChips"
import { formatPhoneNumber } from "@/lib/utils/channelHelpers"

interface ConversationCardProps {
  conversation: Conversation
  channel?: Channel
  isSelected: boolean
  onClick: (id: number) => void
  /** Roving tabindex: solo la opción activa del listbox entra en el orden de tabulación. */
  tabIndex: 0 | -1
  onFocusOption: (id: number) => void
  selectionMode?: boolean
  isChecked?: boolean
  onToggleSelect?: (id: number) => void
  compact?: boolean
}

const ConversationCard = memo(function ConversationCard({
  conversation,
  channel,
  isSelected,
  onClick,
  tabIndex,
  onFocusOption,
  selectionMode = false,
  isChecked = false,
  onToggleSelect,
  compact = false,
}: ConversationCardProps) {
  const { t, language } = useTranslation()
  const cardRef = useRef<HTMLDivElement>(null)

  // En la columna lateral, el chat abierto (p. ej. desde un deep-link) puede
  // estar fuera de vista: lo traemos sin mover la lista si ya se ve.
  useEffect(() => {
    if (compact && isSelected) cardRef.current?.scrollIntoView({ block: "nearest" })
  }, [compact, isSelected])

  const isGroup = conversation.kind === "group"
  const contactName = isGroup
    ? conversation.group?.subject || "Grupo"
    : conversation.contact?.name || t("chats.unnamedContact")
  const contactPhone = isGroup
    ? `${conversation.group?.total_participant_count ?? 0} participantes`
    : formatPhoneNumber(conversation.contact?.phone)
  const leadScore = conversation.leadScore ?? null
  const unreadCount = conversation.unread_count ?? 0
  const isUnread = unreadCount > 0 || Boolean(conversation.unread) || Boolean(conversation.manual_unread)
  const unreadSrText = isUnread
    ? unreadCount > 0
      ? t("chats.unreadBadgeAria", { count: unreadCount })
      : t("chats.unreadDotAria")
    : null

  const formatLastMessageAt = (iso?: string | null) => {
    if (!iso) return "--:--"
    const date = new Date(iso)
    const now = new Date()
    const isSameDay =
      date.getFullYear() === now.getFullYear() &&
      date.getMonth() === now.getMonth() &&
      date.getDate() === now.getDate()

    if (isSameDay) {
      return date.toLocaleTimeString(language, { hour: "2-digit", minute: "2-digit" })
    }

    const yesterday = new Date(now)
    yesterday.setDate(now.getDate() - 1)
    const isYesterday =
      date.getFullYear() === yesterday.getFullYear() &&
      date.getMonth() === yesterday.getMonth() &&
      date.getDate() === yesterday.getDate()

    if (isYesterday) return t("chats.yesterday")

    const diffDays = Math.floor((now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24))
    if (diffDays < 7) {
      return date.toLocaleDateString(language, { weekday: "short" })
    }

    return date.toLocaleDateString(language, { day: "2-digit", month: "2-digit" })
  }

  const handleCardClick = () => {
    if (selectionMode) {
      onToggleSelect?.(conversation.id)
    } else {
      onClick(conversation.id)
    }
  }

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== "Enter" && event.key !== " ") return
    event.preventDefault()
    handleCardClick()
  }

  return (
    <Card
      ref={cardRef}
      role="option"
      aria-selected={selectionMode ? isChecked : isSelected}
      tabIndex={tabIndex}
      data-conversation-id={conversation.id}
      // En el piloto BIMS la bandeja es una lista de filas con divisor, no una pila de tarjetas.
      className={`${compact ? 'px-3 py-2.5' : 'p-3'} cursor-pointer outline-none hover:bg-accent transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 brand:gap-0 brand:rounded-none brand:border-0 brand:border-b brand:border-border brand:bg-transparent brand:shadow-none brand:focus-visible:ring-inset ${isSelected ? 'bg-accent border-primary brand:bg-accent' : ''} ${isChecked ? 'bg-primary/5 border-primary/40 brand:bg-primary/5' : ''}`}
      onClick={handleCardClick}
      onKeyDown={handleKeyDown}
      onFocus={() => onFocusOption(conversation.id)}
    >
      <div className="flex items-center gap-3">
        {/* Indicador visual: el estado lo anuncia aria-selected de la opción.
            Un control interactivo dentro de una opción sería inválido. */}
        {selectionMode && (
          <span
            aria-hidden="true"
            className={`flex size-4 shrink-0 items-center justify-center rounded-[4px] border shadow-xs ${isChecked ? 'border-primary bg-primary text-primary-foreground' : 'border-input dark:bg-input/30'}`}
          >
            {isChecked && <Check className="size-3.5" />}
          </span>
        )}
        <div className="flex items-center gap-2">
          {channel && (
            <PlatformIcon
              type={channelTypeToFilterType(channel.type)}
              className="w-4 h-4"
            />
          )}
          {isGroup ? (
            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted">
              <Users className="h-4 w-4 text-muted-foreground" />
            </div>
          ) : (
            <ContactAvatar
              contactId={conversation.contact?.id}
              name={contactName}
              className="w-8 h-8"
            />
          )}
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between gap-2 mb-1">
            <div className="flex items-center gap-2 min-w-0 flex-1">
              <span className={`text-sm truncate min-w-0 ${isUnread ? 'font-semibold text-foreground' : 'font-medium'}`}>
                {contactName}
              </span>
              {conversation.aiAutoreplyEnabled && (
                <Bot className="size-3.5 shrink-0 text-ai" role="img" aria-label={t("chats.list.aiOn")}>
                  <title>{t("chats.list.aiOn")}</title>
                </Bot>
              )}
              {leadScore != null && leadScore > 0 && (
                <LeadScoreBadge
                  score={leadScore}
                  className="cursor-help shrink-0"
                  title={`${t("chats.leadScore")}: ${leadScore}/100`}
                />
              )}
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <span className={`text-xs ${isUnread ? 'text-primary font-semibold' : 'text-muted-foreground'}`}>
                {formatLastMessageAt(conversation.last_message_at)}
              </span>
              {unreadSrText && <span className="sr-only">{unreadSrText}</span>}
              {isUnread && (
                unreadCount > 0 ? (
                  <Badge
                    aria-hidden="true"
                    className="h-5 min-w-5 rounded-full px-1.5 text-[10px] font-semibold bg-primary hover:bg-primary text-primary-foreground border-0 tabular-nums"
                  >
                    {unreadCount > 99 ? "99+" : unreadCount}
                  </Badge>
                ) : (
                  <span aria-hidden="true" className="h-2 w-2 rounded-full bg-primary" />
                )
              )}
            </div>
          </div>
          {contactPhone && !compact && (
            <p className="text-xs text-muted-foreground truncate tabular-nums tracking-tight">
              {contactPhone}
            </p>
          )}
          <p className={`flex min-w-0 items-center gap-1.5 text-xs ${isUnread ? 'text-foreground/90 font-medium' : 'text-muted-foreground'}`}>
            {/* Pendiente pide que alguien la tome; tomada todavía espera la primera respuesta. */}
            {conversation.humanHandoff && (
              <span
                className={`shrink-0 rounded-full border px-1.5 text-[11px] font-medium leading-4 ${
                  conversation.humanHandoff.status === "pending"
                    ? "border-warning/30 bg-warning/10 text-warning"
                    : "border-border bg-muted text-muted-foreground"
                }`}
              >
                {t(conversation.humanHandoff.status === "pending" ? "chats.list.handedOff" : "chats.list.taken")}
              </span>
            )}
            <span className="truncate">{conversation.last_message}</span>
          </p>
          {!compact && conversation.tags && conversation.tags.length > 0 && (
            <TagChips tags={conversation.tags} maxVisible={2} className="mt-2" />
          )}
          {channel && !compact && (
            <p className="text-xs text-muted-foreground truncate">
              {channel.name}
            </p>
          )}
        </div>
      </div>
    </Card>
  )
})

interface ConversationListboxProps {
  label: string
  conversations: Conversation[]
  channelMap: Map<number, Channel>
  selectedConversationId?: number | null
  onConversationClick: (conversationId: number) => void
  selectionMode?: boolean
  selectedIds?: Set<number>
  onToggleSelect?: (conversationId: number) => void
  compact: boolean
  className: string
}

/**
 * Listbox de WAI-ARIA con roving tabindex: un solo tab stop para toda la
 * lista; flechas, Inicio y Fin mueven el foco; Enter o Espacio abren la
 * conversación (o la marcan en modo selección). Mover el foco no abre el
 * chat: abrirlo carga el hilo, así que pide una acción explícita.
 */
function ConversationListbox({
  label,
  conversations,
  channelMap,
  selectedConversationId,
  onConversationClick,
  selectionMode = false,
  selectedIds,
  onToggleSelect,
  compact,
  className,
}: ConversationListboxProps) {
  const listRef = useRef<HTMLDivElement>(null)
  const [activeId, setActiveId] = useState<number | null>(null)

  const ids = useMemo(() => conversations.map((conversation) => conversation.id), [conversations])
  const idSet = useMemo(() => new Set(ids), [ids])

  // La opción tabulable sigue al foco; si esa conversación ya no está en la
  // lista, cae en la abierta y, si tampoco, en la primera.
  const tabStopId =
    activeId !== null && idSet.has(activeId)
      ? activeId
      : selectedConversationId != null && idSet.has(selectedConversationId)
        ? selectedConversationId
        : ids[0] ?? null

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (ids.length === 0) return
    const current = tabStopId === null ? -1 : ids.indexOf(tabStopId)
    let next: number
    switch (event.key) {
      case "ArrowDown":
        next = Math.min(current + 1, ids.length - 1)
        break
      case "ArrowUp":
        next = Math.max(current - 1, 0)
        break
      case "Home":
        next = 0
        break
      case "End":
        next = ids.length - 1
        break
      default:
        return
    }
    event.preventDefault()
    const nextId = ids[next]
    setActiveId(nextId)
    listRef.current?.querySelector<HTMLElement>(`[data-conversation-id="${nextId}"]`)?.focus()
  }

  return (
    <div
      ref={listRef}
      role="listbox"
      aria-label={label}
      aria-multiselectable={selectionMode || undefined}
      onKeyDown={handleKeyDown}
      className={className}
    >
      {conversations.map((conversation) => (
        <ConversationCard
          key={conversation.id}
          conversation={conversation}
          channel={conversation.channel || channelMap.get(conversation.channelId)}
          isSelected={selectedConversationId === conversation.id}
          onClick={onConversationClick}
          tabIndex={conversation.id === tabStopId ? 0 : -1}
          onFocusOption={setActiveId}
          selectionMode={selectionMode}
          isChecked={selectedIds?.has(conversation.id) ?? false}
          onToggleSelect={onToggleSelect}
          compact={compact}
        />
      ))}
    </div>
  )
}

interface ConversationListProps {
  conversations: Conversation[]
  channels: Channel[]
  isLoading?: boolean
  selectedConversationId?: number | null
  onConversationClick: (conversationId: number) => void
  emptyState?: {
    title: string
    description: string
    action?: { label: string; onClick: () => void }
  }
  /** Falló la carga inicial: se muestra el error con reintento en vez del vacío. */
  onRetryLoad?: () => void
  loadFailed?: boolean
  selectionMode?: boolean
  selectedIds?: Set<number>
  onToggleSelect?: (conversationId: number) => void
  messageResults?: Conversation[]
  isSearchingMessages?: boolean
  hasMore?: boolean
  isLoadingMore?: boolean
  onLoadMore?: () => void
  /** Variante angosta para la columna junto a un hilo abierto. */
  compact?: boolean
}

export function ConversationList({
  conversations,
  channels,
  isLoading = false,
  selectedConversationId,
  onConversationClick,
  emptyState,
  onRetryLoad,
  loadFailed = false,
  selectionMode = false,
  selectedIds,
  onToggleSelect,
  messageResults = [],
  isSearchingMessages = false,
  hasMore = false,
  isLoadingMore = false,
  onLoadMore,
  compact = false,
}: ConversationListProps) {
  const { t } = useTranslation()
  const resolvedEmptyState: NonNullable<ConversationListProps["emptyState"]> = emptyState ?? {
    title: t("chats.empty.inbox.title"),
    description: t("chats.empty.inbox.description"),
  }

  // Build a channel lookup map for O(1) access
  const channelMap = useMemo(() => {
    const map = new Map<number, Channel>()
    for (const ch of channels) {
      map.set(ch.id, ch)
    }
    return map
  }, [channels])

  // A conversation can be returned more than once while API and realtime
  // updates overlap. Render each conversation only once so its React identity
  // remains stable; preserve the API's first (highest-ranked) occurrence.
  const uniqueConversations = useMemo(() => {
    const seenIds = new Set<number>()
    return conversations.filter((conversation) => {
      if (seenIds.has(conversation.id)) return false
      seenIds.add(conversation.id)
      return true
    })
  }, [conversations])

  // Los resultados por mensaje muestran el fragmento encontrado en lugar del
  // último mensaje.
  const uniqueMessageResults = useMemo(() => {
    const seenIds = new Set<number>()
    return messageResults.flatMap((conversation) => {
      if (seenIds.has(conversation.id)) return []
      seenIds.add(conversation.id)
      return conversation.matchedMessageSnippet
        ? [{ ...conversation, last_message: conversation.matchedMessageSnippet }]
        : [conversation]
    })
  }, [messageResults])
  const loadMoreRef = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const target = loadMoreRef.current
    if (!target || !hasMore || isLoadingMore || !onLoadMore) return

    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) onLoadMore()
    }, { rootMargin: "240px" })
    observer.observe(target)
    return () => observer.disconnect()
  }, [hasMore, isLoadingMore, onLoadMore])

  if (isLoading) {
    return (
      <div className="flex-1 p-4 overflow-y-auto">
        <SkeletonList count={5} />
      </div>
    )
  }

  const hasMessageResults = uniqueMessageResults.length > 0

  if (loadFailed && uniqueConversations.length === 0) {
    return (
      <div className="flex-1 p-4 overflow-y-auto">
        <EmptyState
          icon={AlertCircle}
          title={t("chats.loadConversationsError")}
          description={t("chats.channelErrorNetwork")}
          action={onRetryLoad ? { label: t("chats.retry"), onClick: onRetryLoad } : undefined}
        />
      </div>
    )
  }

  if (uniqueConversations.length === 0 && !hasMessageResults && !isSearchingMessages) {
    return (
      <div className="flex-1 p-4 overflow-y-auto">
        <EmptyState
          icon={MessageSquare}
          title={resolvedEmptyState.title}
          description={resolvedEmptyState.description}
          action={resolvedEmptyState.action}
        />
      </div>
    )
  }

  return (
    <div className={`flex-1 overflow-y-auto ${compact ? 'p-2' : 'p-4'} brand:p-0`}>
      <ConversationListbox
        label={t("chats.conversationListLabel")}
        conversations={uniqueConversations}
        channelMap={channelMap}
        selectedConversationId={selectedConversationId}
        onConversationClick={onConversationClick}
        selectionMode={selectionMode}
        selectedIds={selectedIds}
        onToggleSelect={onToggleSelect}
        compact={compact}
        className={`${compact ? 'space-y-1' : 'space-y-2'} brand:space-y-0`}
      />
      {hasMore && (
        <div ref={loadMoreRef} className="flex h-16 items-center justify-center" aria-live="polite">
          {isLoadingMore && <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />}
        </div>
      )}
      {(hasMessageResults || isSearchingMessages) && (
        <div className="mt-4">
          <div className="flex items-center gap-2 px-1 pb-2 brand:px-4 brand:pt-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {t("chats.messageResults")}
            </p>
            {isSearchingMessages && <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />}
          </div>
          <ConversationListbox
            label={t("chats.messageResults")}
            conversations={uniqueMessageResults}
            channelMap={channelMap}
            selectedConversationId={selectedConversationId}
            onConversationClick={onConversationClick}
            compact={compact}
            className="space-y-2 brand:space-y-0"
          />
        </div>
      )}
    </div>
  )
}
