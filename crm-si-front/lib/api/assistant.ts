import type { UIMessage } from "ai"
import { extractError } from "@/lib/api/ai-config"
import { workspaceHeaders } from "@/lib/api/auth-token"

export interface AssistantConversation {
  id: string
  title: string
  updated_at: string | null
}

export type AssistantRating = 1 | -1

export interface AssistantThread {
  messages: UIMessage[]
  feedback: Record<string, AssistantRating>
}

function jsonHeaders(): Record<string, string> {
  return { "Content-Type": "application/json", Accept: "application/json", ...workspaceHeaders() }
}

export async function listAssistantConversations(): Promise<AssistantConversation[]> {
  const res = await fetch("/api/assistant/conversations", { headers: jsonHeaders(), cache: "no-store" })
  if (!res.ok) return []
  const json = await res.json().catch(() => ({}))
  return Array.isArray(json.data) ? json.data : []
}

export async function createAssistantConversation(
  firstMessage: string,
): Promise<{ data?: AssistantConversation; error?: string }> {
  const res = await fetch("/api/assistant/conversations", {
    method: "POST",
    headers: jsonHeaders(),
    body: JSON.stringify({ first_message: firstMessage }),
  })
  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function getAssistantThread(conversationId: string): Promise<AssistantThread | null> {
  const res = await fetch(`/api/assistant/conversations/${encodeURIComponent(conversationId)}/messages`, {
    headers: jsonHeaders(),
    cache: "no-store",
  })
  if (!res.ok) return null
  const json = await res.json().catch(() => ({}))
  return {
    messages: Array.isArray(json.data) ? json.data : [],
    feedback: json.feedback && typeof json.feedback === "object" ? json.feedback : {},
  }
}

export async function sendAssistantFeedback(messageId: string, rating: AssistantRating): Promise<boolean> {
  const res = await fetch(`/api/assistant/messages/${encodeURIComponent(messageId)}/feedback`, {
    method: "PUT",
    headers: jsonHeaders(),
    body: JSON.stringify({ rating }),
  })
  return res.ok
}

/**
 * Transcribe un dictado por voz. El texto vuelve al campo del panel para
 * revisarlo: no se envía como mensaje.
 */
export async function transcribeAssistantAudio(
  audio: File,
): Promise<{ text?: string; error?: string; code?: string }> {
  const form = new FormData()
  form.append("audio", audio)
  // Sin Content-Type: el navegador arma el boundary del multipart.
  let res: Response
  try {
    res = await fetch("/api/assistant/transcriptions", {
      method: "POST",
      headers: { Accept: "application/json", ...workspaceHeaders() },
      body: form,
    })
  } catch {
    // Sin conexión o pedido cortado: el panel lo traduce por código.
    return { code: "network_error" }
  }
  const json = await res.json().catch(() => ({}))
  const code = typeof json.code === "string" ? json.code : undefined
  // Un 429 sin código es el límite por minuto del dictado; con código (p. ej.
  // el cupo diario) trae su propio mensaje.
  if (res.status === 429 && !code) return { code: "voice_rate_limited" }
  if (!res.ok) return { error: extractError(json), code }
  return { text: typeof json.data?.text === "string" ? json.data.text : "" }
}
