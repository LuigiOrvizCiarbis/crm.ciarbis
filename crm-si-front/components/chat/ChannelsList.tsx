'use client'

import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { PlatformIcon } from '@/components/chat/PlatformIcon'
import { EmptyState } from '@/components/EmptyState'
import { MessageSquare, Wifi, WifiOff, Pencil, Check, X, RefreshCw } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Channel, FilterType } from '@/data/types'
import { ChannelType, channelTypeToFilterType, getChannelDisplayName, filterTypeToChannelType } from '@/data/enums'
import {
  getChannelIdentifier,
  isChannelConnected,
  getChannelConversationsCount,
  canSendMessages,
} from '@/lib/utils/channelHelpers'
import { useMemo, useState } from 'react'
import { useTranslation } from '@/hooks/useTranslation'

interface ChannelsListProps {
  channels: Channel[]
  selectedChannel: number | null
  activeFilter: FilterType
  isLoading: boolean
  error: Error | null
  onChannelSelect: (channelId: number) => void
  onRetry?: () => void
  onRenameChannel?: (channelId: number, name: string) => void | Promise<void>
  onSyncMailChannel?: (channelId: number) => void | Promise<void>
}

export function ChannelsList({
  channels,
  selectedChannel,
  activeFilter,
  isLoading,
  error,
  onChannelSelect,
  onRetry,
  onRenameChannel,
  onSyncMailChannel,
}: ChannelsListProps) {
  const { t } = useTranslation()
  const [editingId, setEditingId] = useState<number | null>(null)
  const [draftName, setDraftName] = useState("")
  const [syncingId, setSyncingId] = useState<number | null>(null)

  const handleSync = async (channelId: number) => {
    if (!onSyncMailChannel || syncingId !== null) return
    setSyncingId(channelId)
    try {
      await onSyncMailChannel(channelId)
    } finally {
      setSyncingId(null)
    }
  }

  const startEditing = (channel: Channel) => {
    setEditingId(channel.id)
    setDraftName(channel.name ?? "")
  }

  const cancelEditing = () => {
    setEditingId(null)
    setDraftName("")
  }

  const submitEditing = async (channelId: number) => {
    const trimmed = draftName.trim()
    if (trimmed && onRenameChannel) {
      await onRenameChannel(channelId, trimmed)
    }
    cancelEditing()
  }

  const { connected, disconnected } = useMemo(() => ({
    connected: channels.filter(isChannelConnected),
    disconnected: channels.filter(channel => !isChannelConnected(channel)),
  }), [channels])

  if (isLoading) {
    return (
      <div className="p-4 text-center">
        <p className="text-muted-foreground text-sm">{t("chats.loadingChannels")}</p>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-4">
        <EmptyState
          icon={MessageSquare}
          title={t("chats.errorChannels")}
          description={error.message}
          action={onRetry ? {
            label: t("chats.retry"),
            onClick: onRetry,
          } : undefined}
        />
      </div>
    )
  }

  return (
    <div className="p-4">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-medium text-muted-foreground">
          {t("chats.channels")} ({channels.length})
        </h3>
        {activeFilter !== "todos" && (
          <Badge variant="outline" className="text-xs">
            {getChannelDisplayName(filterTypeToChannelType(activeFilter) || ChannelType.MANUAL)}
          </Badge>
        )}
      </div>

      {/* Canales conectados */}
      {connected.length > 0 && (
        <div className="space-y-2 mb-4">
          <div className="flex items-center gap-2 mb-2">
            <Wifi className="w-3 h-3 text-green-600" />
            <p className="text-xs font-medium text-green-600">
              {t("chats.connected")} ({connected.length})
            </p>
          </div>

          {connected.map((channel) => {
            const count = getChannelConversationsCount(channel)
            const isEditing = editingId === channel.id
            return (
            <Card
              key={channel.id}
              className={`group p-3 transition-colors ${isEditing ? '' : 'cursor-pointer hover:bg-accent'} ${selectedChannel === channel.id ? 'bg-accent border-primary' : ''
                }`}
              onClick={() => !isEditing && onChannelSelect(channel.id)}
            >
              <div className="flex items-center gap-2 sm:gap-3">
                <PlatformIcon
                  type={channelTypeToFilterType(channel.type)}
                  className="w-4 h-4 shrink-0"
                />

                <div className="flex-1 min-w-0">
                  {isEditing ? (
                    <Input
                      autoFocus
                      value={draftName}
                      onChange={(e) => setDraftName(e.target.value)}
                      onClick={(e) => e.stopPropagation()}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault()
                          void submitEditing(channel.id)
                        } else if (e.key === "Escape") {
                          e.preventDefault()
                          cancelEditing()
                        }
                      }}
                      className="h-9 w-full text-base sm:h-8 sm:text-sm"
                    />
                  ) : (
                    <p className="font-medium text-sm truncate">
                      {channel.name}
                      {count > 0 && (
                        <span className="ml-1 text-muted-foreground font-normal">({count})</span>
                      )}
                    </p>
                  )}
                  <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>{getChannelDisplayName(channel.type)}</span>
                    {getChannelIdentifier(channel) && (
                      <>
                        <span>•</span>
                         <span className="truncate tabular-nums tracking-tight">{getChannelIdentifier(channel)}</span>
                      </>
                    )}
                  </div>
                </div>

                <div className="flex shrink-0 items-center gap-0.5">
                  {isEditing ? (
                    <>
                      <button
                        type="button"
                        aria-label={t("chats.save")}
                        className="flex h-9 w-9 items-center justify-center rounded-md text-green-600 hover:bg-accent sm:h-8 sm:w-8"
                        onClick={(e) => { e.stopPropagation(); void submitEditing(channel.id) }}
                      >
                        <Check className="h-4 w-4" />
                      </button>
                      <button
                        type="button"
                        aria-label={t("chats.cancel")}
                        className="flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent sm:h-8 sm:w-8"
                        onClick={(e) => { e.stopPropagation(); cancelEditing() }}
                      >
                        <X className="h-4 w-4" />
                      </button>
                    </>
                  ) : (
                    <>
                      {!canSendMessages(channel.type) && (
                        <Badge variant="secondary" className="text-xs">
                          {t("chats.readOnly")}
                        </Badge>
                      )}
                      {/* Los canales de email entran por polling: el botón
                          permite forzar la sincronización sin esperar el tick. */}
                      {channel.type === ChannelType.MAIL && onSyncMailChannel && (
                        <button
                          type="button"
                          aria-label={t("chats.mailSyncNow")}
                          title={t("chats.mailSyncNow")}
                          disabled={syncingId === channel.id}
                          className="flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground transition-opacity hover:bg-accent hover:text-foreground disabled:opacity-50 sm:h-8 sm:w-8 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 md:focus-visible:opacity-100"
                          onClick={(e) => { e.stopPropagation(); void handleSync(channel.id) }}
                        >
                          <RefreshCw className={`h-4 w-4 ${syncingId === channel.id ? 'animate-spin' : ''}`} />
                        </button>
                      )}
                      {onRenameChannel && (
                        <button
                          type="button"
                          aria-label={t("chats.renameChannel")}
                          className="flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground transition-opacity hover:bg-accent hover:text-foreground sm:h-8 sm:w-8 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 md:focus-visible:opacity-100"
                          onClick={(e) => { e.stopPropagation(); startEditing(channel) }}
                        >
                          <Pencil className="h-4 w-4" />
                        </button>
                      )}
                    </>
                  )}
                </div>
              </div>
            </Card>
          )})}
        </div>
      )}

      {/* Canales desconectados */}
      {disconnected.length > 0 && (
        <div className="space-y-2 mb-4">
          <div className="flex items-center gap-2 mb-2">
            <WifiOff className="w-3 h-3 text-red-600" />
            <p className="text-xs font-medium text-red-600">
              {t("chats.disconnected")} ({disconnected.length})
            </p>
          </div>

          {disconnected.map((channel) => (
            <Card key={channel.id} className="p-3 opacity-60">
              <div className="flex items-center gap-3">
                <PlatformIcon
                  type={channelTypeToFilterType(channel.type)}
                  className="w-4 h-4"
                />

                <div className="flex-1 min-w-0">
                  <p className="font-medium text-sm truncate">{channel.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {getChannelDisplayName(channel.type)} • {t("chats.disconnectedLabel")}
                  </p>
                </div>

                <Badge variant="destructive" className="text-xs">
                  {t("chats.offline")}
                </Badge>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Estado vacío */}
      {channels.length === 0 && (
        <div className="mb-4">
          <EmptyState
            icon={MessageSquare}
            title={t("chats.noChannels")}
            description={`${t("chats.noChannelsDesc")}${activeFilter !== "todos" ? ` de ${getChannelDisplayName(filterTypeToChannelType(activeFilter) || ChannelType.MANUAL)}` : ""}`}
          />
        </div>
      )}

    </div>
  )
}
