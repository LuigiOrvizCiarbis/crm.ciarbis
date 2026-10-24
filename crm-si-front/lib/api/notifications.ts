import { throwApiError } from "./api-error"
import { getAuthToken, workspaceHeaders } from "./auth-token"
import type { ProfilePreferences } from "./profile"

export type NotificationType =
  | "message.received"
  | "conversation.assigned"
  | "conversation.unassigned"
  | "handoff.requested"
  | "task.assigned"
  | "import.completed"
  | "import.failed"
  | "channel.token_invalid"
  | "trial.expiring"

export const NOTIFICATION_TYPES: NotificationType[] = [
  "message.received",
  "conversation.assigned",
  "conversation.unassigned",
  "handoff.requested",
  "task.assigned",
  "import.completed",
  "import.failed",
  "channel.token_invalid",
  "trial.expiring",
]

/** Campos que arma DomainNotifier / NotifyInboundMessageJob; cada tipo usa un subconjunto. */
export interface AppNotificationData {
  url?: string
  conversation_id?: number
  contact_name?: string | null
  preview?: string
  actor_name?: string | null
  summary?: string | null
  task_name?: string
  filename?: string | null
  channel_name?: string
  days_left?: number
  [key: string]: unknown
}

export interface AppNotification {
  id: number
  tenant_id: number
  type: NotificationType
  group_key: string | null
  data: AppNotificationData
  count: number
  read_at: string | null
  created_at: string
  updated_at: string
}

export interface NotificationPreferences {
  types: Record<NotificationType, boolean>
  sound: boolean
  browser: boolean
}

function requireToken(): string {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")
  return token
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    headers: {
      Authorization: `Bearer ${requireToken()}`,
      Accept: "application/json",
      "Content-Type": "application/json",
      ...workspaceHeaders(),
      ...options.headers,
    },
    cache: "no-store",
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al procesar notificaciones")
  }

  return payload as T
}

export function getNotifications(cursor?: string | null) {
  const query = new URLSearchParams()
  if (cursor) query.set("cursor", cursor)
  return request<{ data: AppNotification[]; next_cursor: string | null; unread_count: number }>(
    `/api/notifications${query.toString() ? `?${query}` : ""}`,
  )
}

export function markNotificationRead(id: number) {
  return request<{ unread_count: number }>(`/api/notifications/${id}/read`, { method: "POST" })
}

export function markAllNotificationsRead() {
  return request<{ unread_count: number }>("/api/notifications/read-all", { method: "POST" })
}

export function markNotificationGroupRead(groupKey: string) {
  return request<{ unread_count: number }>("/api/notifications/read-group", {
    method: "POST",
    body: JSON.stringify({ group_key: groupKey }),
  })
}

export async function updateNotificationPreferences(input: Partial<NotificationPreferences>) {
  const payload = await request<{ data: { preferences: ProfilePreferences & { notifications: NotificationPreferences } } }>(
    "/api/profile/notification-preferences",
    { method: "PUT", body: JSON.stringify(input) },
  )
  return payload.data.preferences
}
