import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export type ManualAiDraft = {
  id: number
  conversation_id: number
  source_message_id: number
  status: "pending" | "ready" | "failed" | "cancelled" | "expired"
  content: string | null
  error_code?: string | null
  expires_at?: string | null
}

async function request<T>(conversationId: number, method: string, body?: unknown): Promise<T | null> {
  const token = getAuthToken()
  if (!token) throw new Error("Token faltante")
  const response = await fetch(`/api/conversations/${conversationId}/ai-draft`, {
    method,
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json", ...(body ? { "Content-Type": "application/json" } : {}) },
    body: body ? JSON.stringify(body) : undefined,
    cache: "no-store",
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throwApiError(response.status, payload, "No se pudo gestionar el borrador de IA")
  return (payload.data ?? null) as T | null
}

export const getManualAiDraft = (id: number) => request<ManualAiDraft>(id, "GET")
export const requestManualAiDraft = (id: number, sourceMessageId: number) => request<ManualAiDraft>(id, "POST", { source_message_id: sourceMessageId })
export const cancelManualAiDraft = (id: number) => request<ManualAiDraft>(id, "DELETE")
