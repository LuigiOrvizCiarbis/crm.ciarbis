import { memo, useMemo } from "react"
import { Card } from "@/components/ui/card"
import { ContactAvatar } from "@/components/contact-avatar"
import { Badge } from "@/components/ui/badge"
import { Checkbox } from "@/components/ui/checkbox"
import { LeadScoreBadge } from "@/components/Badges"
import { PlatformIcon } from "@/components/chat/PlatformIcon"
import { EmptyState } from "@/components/EmptyState"
import { SkeletonList } from "@/components/Skeleton"
import { Loader2, MessageSquare } from "lucide-react"
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
  selectionMode?: boolean
  isChecked?: boolean
  onToggleSelect?: (id: number) => void
}

const ConversationCard = memo(function ConversationCard({
  conversation,
  channel,
  isSelected,
  onClick,
  selectionMode = false,
  isChecked = false,
  onToggleSelect,
}: ConversationCardProps) {
  const { t } = useTranslation()

  const contactName = conversation.contact?.name || t("chats.unnamedContact")
  const contactPhone = formatPhoneNumber(conversation.contact?.phone)
  const leadScore = conversation.leadScore ?? null
  const unreadCount = conversation.unread_count ?? 0
  const isUnread = unreadCount > 0 || Boolean(conversation.unread) || Boolean(conversation.manual_unread)
  const unreadAriaLabel = isUnread
    ? unreadCount > 0
      ? t(unreadCount === 1 ? "chats.unreadAriaSingular" : "chats.unreadAriaPlural", {
          count: unreadCount,
          name: contactName,
        })
      : t("chats.unreadDotAria")
    : undefined

  const formatLastMessageAt = (iso?: string | null) => {
    if (!iso) return "--:--"
    const date = new Date(iso)
    const now = new Date()
    const isSameDay =
      date.getFullYear() === now.getFullYear() &&
      date.getMonth() === now.getMonth() &&
      date.getDate() === now.getDate()

    if (isSameDay) {
      return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: true })
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
      return date.toLocaleDateString([], { weekday: "short" })
    }

    return date.toLocaleDateString([], { day: "2-digit", month: "2-digit" })
  }

  const handleCardClick = () => {
    if (selectionMode) {
      onToggleSelect?.(conversation.id)
    } else {
      onClick(conversation.id)
    }
  }

  return (
    <Card
      className={`p-3 cursor-pointer hover:bg-accent transition-colors ${isSelected ? 'bg-accent border-primary' : ''} ${isChecked ? 'bg-primary/5 border-primary/40' : ''} ${isUnread && !isSelected ? 'border-l-2 border-l-blue-500' : ''}`}
      onClick={handleCardClick}
      aria-label={unreadAriaLabel}
    >
      <div className="flex items-center gap-3">
        {selectionMode && (
          <Checkbox
            checked={isChecked}
            onCheckedChange={() => onToggleSelect?.(conversation.id)}
            onClick={(e) => e.stopPropagation()}
            aria-label="Seleccionar conversación"
          />
        )}
        <div className="flex items-center gap-2">
          {channel && (
            <PlatformIcon
              type={channelTypeToFilterType(channel.type)}
              className="w-4 h-4"
            />
          )}
          <ContactAvatar
            contactId={conversation.contact?.id}
            name={contactName}
            className="w-8 h-8"
          />
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between gap-2 mb-1">
            <div className="flex items-center gap-2 min-w-0 flex-1">
              <span className={`text-sm truncate min-w-0 ${isUnread ? 'font-semibold text-foreground' : 'font-medium'}`}>
                {contactName}
              </span>
              {leadScore != null && leadScore > 0 && (
                <LeadScoreBadge
                  score={leadScore}
                  className="cursor-help shrink-0"
                  title={`${t("chats.leadScore")}: ${leadScore}/100`}
                />
              )}
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <span className={`text-xs ${isUnread ? 'text-blue-500 font-semibold' : 'text-muted-foreground'}`}>
                {formatLastMessageAt(conversation.last_message_at)}
              </span>
              {isUnread && (
                unreadCount > 0 ? (
                  <Badge
                    className="h-5 min-w-5 rounded-full px-1.5 text-[10px] font-semibold bg-blue-500 hover:bg-blue-500 text-white border-0 tabular-nums"
                    aria-label={t("chats.unreadBadgeAria", { count: unreadCount })}
                  >
                    {unreadCount > 99 ? "99+" : unreadCount}
                  </Badge>
                ) : (
                  <span
                    className="h-2 w-2 rounded-full bg-blue-500"
                    aria-label={t("chats.unreadDotAria")}
                  />
                )
              )}
            </div>
          </div>
          {contactPhone && (
            <p className="text-xs text-muted-foreground/80 truncate tabular-nums tracking-tight">
              {contactPhone}
            </p>
          )}
          <p className={`text-xs truncate ${isUnread ? 'text-foreground/90 font-medium' : 'text-muted-foreground'}`}>
            {conversation.last_message}
          </p>
          {conversation.tags && conversation.tags.length > 0 && (
            <TagChips tags={conversation.tags} maxVisible={2} className="mt-2" />
          )}
          {channel && (
            <p className="text-xs text-muted-foreground/70 truncate">
              {channel.name}
            </p>
          )}
        </div>
      </div>
    </Card>
  )
})

interface ConversationListProps {
  conversations: Conversation[]
  channels: Channel[]
  isLoading?: boolean
  selectedConversationId?: number | null
  onConversationClick: (conversationId: number) => void
  emptyState?: {
    title: string
    description: string
  }
  selectionMode?: boolean
  selectedIds?: Set<number>
  onToggleSelect?: (conversationId: number) => void
  messageResults?: Conversation[]
  isSearchingMessages?: boolean
}

export function ConversationList({
  conversations,
  channels,
  isLoading = false,
  selectedConversationId,
  onConversationClick,
  emptyState,
  selectionMode = false,
  selectedIds,
  onToggleSelect,
  messageResults = [],
  isSearchingMessages = false,
}: ConversationListProps) {
  const { t } = useTranslation()
  const resolvedEmptyState = emptyState ?? {
    title: t("chats.noConversations"),
    description: t("chats.noConversationsDesc"),
  }

  // Build a channel lookup map for O(1) access
  const channelMap = useMemo(() => {
    const map = new Map<number, Channel>()
    for (const ch of channels) {
      map.set(ch.id, ch)
    }
    return map
  }, [channels])

  if (isLoading) {
    return (
      <div className="flex-1 p-4 overflow-y-auto">
        <SkeletonList count={5} />
      </div>
    )
  }

  const hasMessageResults = messageResults.length > 0

  if (conversations.length === 0 && !hasMessageResults && !isSearchingMessages) {
    return (
      <div className="flex-1 p-4 overflow-y-auto">
        <EmptyState
          icon={MessageSquare}
          title={resolvedEmptyState.title}
          description={resolvedEmptyState.description}
        />
      </div>
    )
  }

  return (
    <div className="flex-1 p-4 overflow-y-auto">
      <div className="space-y-2">
        {conversations.map((conversation) => (
          <ConversationCard
            key={conversation.id}
            conversation={conversation}
            channel={conversation.channel || channelMap.get(conversation.channelId)}
            isSelected={selectedConversationId === conversation.id}
            onClick={onConversationClick}
            selectionMode={selectionMode}
            isChecked={selectedIds?.has(conversation.id) ?? false}
            onToggleSelect={onToggleSelect}
          />
        ))}
      </div>
      {(hasMessageResults || isSearchingMessages) && (
        <div className="mt-4">
          <div className="flex items-center gap-2 px-1 pb-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {t("chats.messageResults")}
            </p>
            {isSearchingMessages && <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />}
          </div>
          <div className="space-y-2">
            {messageResults.map((conversation) => (
              <ConversationCard
                key={`message-result-${conversation.id}`}
                conversation={
                  conversation.matchedMessageSnippet
                    ? { ...conversation, last_message: conversation.matchedMessageSnippet }
                    : conversation
                }
                channel={conversation.channel || channelMap.get(conversation.channelId)}
                isSelected={selectedConversationId === conversation.id}
                onClick={onConversationClick}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
