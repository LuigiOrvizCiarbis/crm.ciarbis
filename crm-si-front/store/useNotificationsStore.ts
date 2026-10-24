import { create } from "zustand"
import {
  getNotifications,
  markAllNotificationsRead,
  markNotificationGroupRead,
  markNotificationRead,
  type AppNotification,
} from "@/lib/api/notifications"

interface NotificationsState {
  items: AppNotification[]
  unreadCount: number
  nextCursor: string | null
  loading: boolean
  loaded: boolean
  fetch: () => Promise<void>
  loadMore: () => Promise<void>
  markRead: (id: number) => Promise<void>
  markAllRead: () => Promise<void>
  markGroupRead: (groupKey: string) => Promise<void>
  /** Alta o actualización (notificación agrupada: mismo id, count++). */
  upsert: (notification: AppNotification) => void
  /** Estado leído llegado desde otra pestaña/dispositivo. */
  applyRead: (ids: number[] | null, unreadCount: number) => void
  reset: () => void
}

const sortByRecent = (items: AppNotification[]) =>
  [...items].sort((a, b) => b.updated_at.localeCompare(a.updated_at) || b.id - a.id)

/**
 * Generación de la bandeja: reset() la incrementa (cambio de usuario o de
 * workspace). Una respuesta pedida en una generación anterior se descarta para
 * que una request en vuelo del workspace viejo no pise al nuevo.
 */
let generation = 0

const markLocal = (items: AppNotification[], ids: number[] | null) => {
  const now = new Date().toISOString()
  return items.map((item) => (!item.read_at && (ids === null || ids.includes(item.id)) ? { ...item, read_at: now } : item))
}

export const useNotificationsStore = create<NotificationsState>((set, get) => {
  /**
   * Marca leídas en local antes de confirmar con el server. Si el request
   * falla revierte sólo lo que tocó: los ids vuelven a no leídos y el delta se
   * suma al contador actual, así no se pierde lo que llegó en vivo mientras.
   * `ids` null = todas.
   */
  const markOptimistically = async (ids: number[] | null, request: () => Promise<{ unread_count: number }>) => {
    const requestGeneration = generation
    const marked = get().items.filter((item) => !item.read_at && (ids === null || ids.includes(item.id))).map((item) => item.id)
    const delta = ids === null ? get().unreadCount : marked.length
    set((state) => ({ items: markLocal(state.items, marked), unreadCount: Math.max(0, state.unreadCount - delta) }))

    try {
      const { unread_count } = await request()
      if (requestGeneration === generation) set({ unreadCount: unread_count })
    } catch (error) {
      if (requestGeneration === generation) {
        set((state) => ({
          items: state.items.map((item) => (marked.includes(item.id) ? { ...item, read_at: null } : item)),
          unreadCount: state.unreadCount + delta,
        }))
      }
      throw error
    }
  }

  return {
    items: [],
    unreadCount: 0,
    nextCursor: null,
    loading: false,
    loaded: false,

    fetch: async () => {
      const requestGeneration = generation
      set({ loading: true })
      try {
        const page = await getNotifications()
        if (requestGeneration !== generation) return
        set({ items: page.data, nextCursor: page.next_cursor, unreadCount: page.unread_count, loaded: true })
      } finally {
        if (requestGeneration === generation) set({ loading: false })
      }
    },

    loadMore: async () => {
      const { nextCursor, loading } = get()
      if (!nextCursor || loading) return
      const requestGeneration = generation
      set({ loading: true })
      try {
        const page = await getNotifications(nextCursor)
        if (requestGeneration !== generation) return
        set((state) => {
          const known = new Set(state.items.map((item) => item.id))
          return {
            items: [...state.items, ...page.data.filter((item) => !known.has(item.id))],
            nextCursor: page.next_cursor,
            unreadCount: page.unread_count,
          }
        })
      } finally {
        if (requestGeneration === generation) set({ loading: false })
      }
    },

    markRead: async (id) => {
      const target = get().items.find((item) => item.id === id)
      if (target?.read_at) return
      await markOptimistically([id], () => markNotificationRead(id))
    },

    markAllRead: () => markOptimistically(null, markAllNotificationsRead),

    markGroupRead: async (groupKey) => {
      const ids = get().items.filter((item) => item.group_key === groupKey).map((item) => item.id)
      await markOptimistically(ids, () => markNotificationGroupRead(groupKey))
    },

    upsert: (notification) =>
      set((state) => {
        const previous = state.items.find((item) => item.id === notification.id)
        const wasUnread = previous ? !previous.read_at : false
        const isUnread = !notification.read_at
        return {
          items: sortByRecent([notification, ...state.items.filter((item) => item.id !== notification.id)]),
          unreadCount: state.unreadCount + (isUnread && !wasUnread ? 1 : 0),
        }
      }),

    applyRead: (ids, unreadCount) => set((state) => ({ items: markLocal(state.items, ids), unreadCount })),

    reset: () => {
      generation++
      set({ items: [], unreadCount: 0, nextCursor: null, loaded: false, loading: false })
    },
  }
})
