import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export interface MailIntake {
  id: number
  channel_id: number
  channel?: { id: number; name: string; type: string }
  status: "pending" | "accepted" | "rejected"
  classification_reason: string
  from_email: string
  from_name?: string | null
  subject?: string | null
  body_text?: string | null
  body_html?: string | null
  to?: Array<{ email: string; name?: string | null }>
  cc?: Array<{ email: string; name?: string | null }>
  attachments?: Array<{ url: string; filename: string; mime_type: string; type: string }>
  attachments_count: number
  has_remote_images?: boolean
  received_at?: string | null
  expires_at?: string | null
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")
  const response = await fetch(`/api/mail-intakes${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, ...(init.body ? { "Content-Type": "application/json" } : {}), ...init.headers }, cache: "no-store" })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throwApiError(response.status, payload, "Error al gestionar el email")
  return payload as T
}

export async function getMailIntakes(status: "pending" | "rejected" = "pending") { return request<{ data: MailIntake[] }>(`?status=${status}`) }
export async function getMailIntake(id: number) { return request<{ data: MailIntake }>(`/${id}`) }
export async function getMailIntakeCount() { return request<{ data: { pending: number } }>("/count") }
export async function approveMailIntake(id: number, allow = false) { return request<{ data: { conversation_id: number } }>(`/${id}/${allow ? "approve-and-allow" : "approve"}`, { method: "POST" }) }
export async function rejectMailIntake(id: number, block = false) { return request<{ data: MailIntake }>(`/${id}/${block ? "reject-and-block" : "reject"}`, { method: "POST" }) }
export async function restoreMailIntake(id: number) { return request<{ data: MailIntake }>(`/${id}/restore`, { method: "POST" }) }
