import { useMemo } from 'react'
import { Conversation, Channel, FilterType } from '@/data/types'
import { filterTypeToChannelType } from '@/data/enums'

interface UseConversationFiltersProps {
  conversations: Conversation[]
  channels: Channel[]
  activeFilter: FilterType
  selectedChannelId?: number | null
}

/**
 * Hook para filtrar conversaciones según canal y filtro activo.
 * Sigue el patrón multi-tenancy: backend auto-filtra por tenant_id.
 */
export function useConversationFilters({
  conversations,
  channels,
  activeFilter,
  selectedChannelId,
}: UseConversationFiltersProps) {

  // ✅ Filtrar channels por tipo
  const filteredChannels = useMemo(() => {
    if (activeFilter === "todos") {
      return channels
    }

    const targetType = filterTypeToChannelType(activeFilter)
    if (!targetType) return channels

    return channels.filter(channel => channel.type === targetType)
  }, [channels, activeFilter])

  // ✅ Filtrar conversaciones por canal seleccionado + filtro activo
  const filteredConversations = useMemo(() => {
    let result = conversations

    // Nota: No filtramos por selectedChannelId aquí porque el backend ya lo hace.
    // Las conversaciones derivadas tienen channel_id del canal original pero
    // assigned_to del usuario destino, y el backend las incluye correctamente.

    // Filtro por tipo de filtro activo
    if (activeFilter === "todos") {
      return result
    }

    // Filtro por tipo de canal (whatsapp, instagram, etc.)
    const targetType = filterTypeToChannelType(activeFilter)
    if (!targetType) return result

    // Obtener IDs de channels que coinciden con el tipo
    const matchingChannelIds = channels
      .filter(channel => channel.type === targetType)
      .map(channel => channel.id)

    return result.filter(conv => matchingChannelIds.includes(conv.channelId))
  }, [conversations, channels, activeFilter, selectedChannelId])

  // ✅ Separar channels por estado
  const connectedChannels = useMemo(() =>
    filteredChannels.filter(ch => ch.status === "active"),
    [filteredChannels]
  )

  const disconnectedChannels = useMemo(() =>
    filteredChannels.filter(ch => ch.status === "disconnected"),
    [filteredChannels]
  )

  // ✅ Stats útiles
  const stats = useMemo(() => ({
    totalConversations: conversations.length,
    filteredConversationsCount: filteredConversations.length,
    unreadCount: conversations.filter(c => c.unread_count && c.unread_count > 0).length,
    totalChannels: channels.length,
    connectedChannelsCount: connectedChannels.length,
    disconnectedChannelsCount: disconnectedChannels.length,
  }), [conversations, filteredConversations, channels, connectedChannels, disconnectedChannels])

  return {
    // Datos filtrados
    filteredConversations,
    filteredChannels,
    connectedChannels,
    disconnectedChannels,

    // Stats
    stats,
  }
}