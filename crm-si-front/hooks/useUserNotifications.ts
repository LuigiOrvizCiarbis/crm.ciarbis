"use client"

import { useEffect, useRef } from "react"
import { useRouter } from "next/navigation"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { getActiveConversationId } from "@/lib/activeConversation"
import { getWorkspaceId } from "@/lib/api/auth-token"
import type { AppNotification } from "@/lib/api/notifications"
import { describeNotification, playNotificationSound, showBrowserNotification } from "@/lib/notificationPresentation"
import { getPusher } from "@/lib/pusher"
import { useAuthStore } from "@/store/useAuthStore"
import { useNotificationsStore } from "@/store/useNotificationsStore"

interface ReadEvent {
  tenant_id: number
  ids: number[] | null
  unread_count: number
}

const TITLE_PREFIX = /^\(\d+\+?\) /

/**
 * Listener global (montado una vez en AppShell) del canal privado del usuario:
 * mantiene la bandeja al día y avisa con toast, notificación del navegador y
 * sonido según las preferencias del perfil.
 */
export function useUserNotifications() {
  const userId = useAuthStore((state) => state.user?.id)
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId)
  const unreadCount = useNotificationsStore((state) => state.unreadCount)
  const router = useRouter()
  const { t } = useTranslation()
  const { addToast, removeToast } = useToast()

  // Refs para no re-suscribir el canal cuando cambian idioma/router/toasts.
  const presenterRef = useRef<(notification: AppNotification) => void>(() => {})
  useEffect(() => {
    presenterRef.current = (notification) => {
      const store = useNotificationsStore.getState()
      const preferences = useAuthStore.getState().user?.preferences?.notifications
      const text = describeNotification(notification, t)
      const open = () => {
        store.markRead(notification.id).catch(() => {})
        if (notification.data?.url) router.push(notification.data.url)
      }

      if (document.visibilityState === "visible") {
        const toastId = `notification-${notification.id}`
        removeToast(toastId)
        addToast({ id: toastId, type: "info", title: text.title, description: text.body, duration: 6000, onClick: open })
      } else if (preferences?.browser) {
        showBrowserNotification(notification, text, open)
      }

      if (preferences?.sound ?? true) playNotificationSound()
    }
  })

  useEffect(() => {
    const store = useNotificationsStore.getState()
    store.reset()
    if (!userId) return
    store.fetch().catch(() => {})
  }, [userId, activeWorkspaceId])

  useEffect(() => {
    if (!userId) return

    const pusher = getPusher()
    const channelName = `private-App.Models.User.${userId}`
    const channel = pusher.subscribe(channelName)
    // El canal del usuario no está atado al workspace: descartamos lo de otros.
    const isCurrentWorkspace = (tenantId: number) => {
      const workspaceId = getWorkspaceId() ?? useAuthStore.getState().activeWorkspaceId
      return workspaceId == null || workspaceId === tenantId
    }

    const onCreated = ({ notification }: { notification: AppNotification }) => {
      if (!isCurrentWorkspace(notification.tenant_id)) return
      const store = useNotificationsStore.getState()
      store.upsert(notification)

      const viewingConversation = notification.type === "message.received"
        && notification.data?.conversation_id === getActiveConversationId()
        && document.visibilityState === "visible"
      if (viewingConversation && notification.group_key) {
        store.markGroupRead(notification.group_key).catch(() => {})
        return
      }

      presenterRef.current(notification)
    }

    const onRead = (event: ReadEvent) => {
      if (isCurrentWorkspace(event.tenant_id)) useNotificationsStore.getState().applyRead(event.ids, event.unread_count)
    }

    // Tras una reconexión pudimos perder eventos: se resincroniza la bandeja.
    let connectedOnce = pusher.connection.state === "connected"
    const onConnected = () => {
      if (connectedOnce) useNotificationsStore.getState().fetch().catch(() => {})
      connectedOnce = true
    }

    channel.bind("notification.created", onCreated)
    channel.bind("notifications.read", onRead)
    pusher.connection.bind("connected", onConnected)

    return () => {
      channel.unbind("notification.created", onCreated)
      channel.unbind("notifications.read", onRead)
      pusher.connection.unbind("connected", onConnected)
      pusher.unsubscribe(channelName)
    }
  }, [userId])

  // Prefijo "(n)" en el título de la pestaña; Next lo reescribe al navegar.
  useEffect(() => {
    const apply = () => {
      const base = document.title.replace(TITLE_PREFIX, "")
      const next = unreadCount > 0 ? `(${unreadCount > 99 ? "99+" : unreadCount}) ${base}` : base
      if (document.title !== next) document.title = next
    }
    apply()
    const titleElement = document.querySelector("title")
    if (!titleElement) return
    const observer = new MutationObserver(apply)
    observer.observe(titleElement, { childList: true, characterData: true, subtree: true })
    return () => observer.disconnect()
  }, [unreadCount])
}
