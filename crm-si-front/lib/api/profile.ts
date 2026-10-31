import { getAuthToken } from "./auth-token"
import type { User } from "@/store/useAuthStore"

export interface ProfileUpdateInput {
  name: string
  phone?: string | null
  job_title?: string | null
}

export interface ProfilePreferences {
  locale: string
  timezone: string
  date_format: string
}

export interface ProfileSession {
  id: number
  name: string
  ip_address: string | null
  user_agent: string | null
  created_at: string
  last_used_at: string | null
  is_current: boolean
}

function extractError(payload: any, fallback: string): string {
  const firstError = payload?.errors && typeof payload.errors === "object"
    ? Object.values(payload.errors).flat()[0]
    : null
  return String(firstError || payload?.message || fallback)
}

function authHeaders(token: string, json = true): HeadersInit {
  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
  }
  if (json) headers["Content-Type"] = "application/json"
  return headers
}

export async function updateProfile(input: ProfileUpdateInput): Promise<{ data?: User; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch("/api/profile", {
    method: "PUT",
    headers: authHeaders(token),
    body: JSON.stringify(input),
  })
  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    return { error: extractError(payload, "No se pudo actualizar el perfil.") }
  }

  return { data: payload?.data }
}

export async function updatePassword(input: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch("/api/profile/password", {
    method: "PUT",
    headers: authHeaders(token),
    body: JSON.stringify(input),
  })
  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    return { error: extractError(payload, "No se pudo cambiar la contraseña.") }
  }

  return {}
}

export async function updatePreferences(
  input: ProfilePreferences,
): Promise<{ data?: ProfilePreferences; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch("/api/profile/preferences", {
    method: "PUT",
    headers: authHeaders(token),
    body: JSON.stringify(input),
  })
  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    return { error: extractError(payload, "No se pudieron guardar las preferencias.") }
  }

  return { data: payload?.data?.preferences }
}

export async function uploadAvatar(file: File): Promise<{ avatar_url?: string; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const formData = new FormData()
  formData.append("avatar", file)

  const response = await fetch("/api/profile/avatar", {
    method: "POST",
    headers: { Authorization: `Bearer ${token}` },
    body: formData,
  })
  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    return { error: extractError(payload, "No se pudo subir la imagen.") }
  }

  return { avatar_url: payload?.data?.avatar_url }
}

export async function deleteAvatar(): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch("/api/profile/avatar", {
    method: "DELETE",
    headers: authHeaders(token, false),
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    return { error: extractError(payload, "No se pudo quitar la imagen.") }
  }

  return {}
}

export async function getSessions(): Promise<ProfileSession[]> {
  const token = getAuthToken()
  if (!token) return []

  const response = await fetch("/api/profile/sessions", {
    headers: authHeaders(token, false),
    cache: "no-store",
  })
  if (!response.ok) return []

  const payload = await response.json().catch(() => ({}))
  return payload?.data ?? []
}

export async function revokeSession(id: number): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch(`/api/profile/sessions/${id}`, {
    method: "DELETE",
    headers: authHeaders(token, false),
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    return { error: extractError(payload, "No se pudo cerrar la sesión.") }
  }

  return {}
}

export async function revokeOtherSessions(): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch("/api/profile/sessions", {
    method: "DELETE",
    headers: authHeaders(token, false),
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    return { error: extractError(payload, "No se pudieron cerrar las demás sesiones.") }
  }

  return {}
}
