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

const markLocal = (items: AppNotification[], ids: number[] | null) => {
  const now = new Date().toISOString()
  return items.map((item) => (!item.read_at && (ids === null || ids.includes(item.id)) ? { ...item, read_at: now } : item))
}

export const useNotificationsStore = create<NotificationsState>((set, get) => ({
  items: [],
  unreadCount: 0,
  nextCursor: null,
  loading: false,
  loaded: false,

  fetch: async () => {
    set({ loading: true })
    try {
      const page = await getNotifications()
      set({ items: page.data, nextCursor: page.next_cursor, unreadCount: page.unread_count, loaded: true })
    } finally {
      set({ loading: false })
    }
  },

  loadMore: async () => {
    const { nextCursor, loading } = get()
    if (!nextCursor || loading) return
    set({ loading: true })
    try {
      const page = await getNotifications(nextCursor)
      set((state) => {
        const known = new Set(state.items.map((item) => item.id))
        return {
          items: [...state.items, ...page.data.filter((item) => !known.has(item.id))],
          nextCursor: page.next_cursor,
          unreadCount: page.unread_count,
        }
      })
    } finally {
      set({ loading: false })
    }
  },

  markRead: async (id) => {
    const target = get().items.find((item) => item.id === id)
    if (target?.read_at) return
    set((state) => ({ items: markLocal(state.items, [id]), unreadCount: Math.max(0, state.unreadCount - 1) }))
    const { unread_count } = await markNotificationRead(id)
    set({ unreadCount: unread_count })
  },

  markAllRead: async () => {
    set((state) => ({ items: markLocal(state.items, null), unreadCount: 0 }))
    const { unread_count } = await markAllNotificationsRead()
    set({ unreadCount: unread_count })
  },

  markGroupRead: async (groupKey) => {
    const ids = get().items.filter((item) => item.group_key === groupKey && !item.read_at).map((item) => item.id)
    if (ids.length > 0) {
      set((state) => ({ items: markLocal(state.items, ids), unreadCount: Math.max(0, state.unreadCount - ids.length) }))
    }
    const { unread_count } = await markNotificationGroupRead(groupKey)
    set({ unreadCount: unread_count })
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

  reset: () => set({ items: [], unreadCount: 0, nextCursor: null, loaded: false }),
}))
