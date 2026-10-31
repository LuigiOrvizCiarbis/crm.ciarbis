import { getAuthToken, workspaceHeaders } from "./auth-token"

export type GoogleCalendarConnectionStatus = "connected" | "needs_reauth"

export interface GoogleCalendarConnection {
  google_email: string
  status: GoogleCalendarConnectionStatus
  connected_at: string | null
}

export type TaskCalendarSyncStatus = "pending" | "synced" | "error" | "paused"

export interface TaskCalendarSync {
  status: TaskCalendarSyncStatus
  html_link: string | null
  meet_link: string | null
  last_error: string | null
  synced_at: string | null
}

export async function getGoogleCalendarConnection(): Promise<GoogleCalendarConnection | null> {
  const token = getAuthToken()
  if (!token) return null

  const res = await fetch("/api/google-calendar/connection", {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return null
  const json = await res.json()
  return json.data ?? null
}

export async function getGoogleCalendarAuthorizationUrl(): Promise<{ data?: string; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch("/api/google-calendar/authorization-url", {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json", ...workspaceHeaders() },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.url }
}

export async function disconnectGoogleCalendar(): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch("/api/google-calendar/connection", {
    method: "DELETE",
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return {}
}

export async function retryTaskCalendarSync(taskId: string): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch(`/api/tasks/${taskId}/google-calendar/retry`, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return {}
}

function extractError(json: any): string {
  if (json?.errors && typeof json.errors === "object") {
    const first = Object.values(json.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return json?.message || "Error"
}
