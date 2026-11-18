import { throwApiError } from "./api-error"

export interface Note {
  id: number
  body: string
  contactId: number | null
  conversationId: number | null
  author: string | null
  createdAt: string
}

export interface CreateNotePayload {
  body: string
  contact_id?: number | null
  conversation_id?: number | null
}

function normalizeNote(apiNote: any): Note {
  return {
    id: apiNote.id,
    body: apiNote.body,
    contactId: apiNote.contact_id ?? null,
    conversationId: apiNote.conversation_id ?? null,
    author: apiNote.author?.name ?? null,
    createdAt: apiNote.created_at,
  }
}

export async function getNotes(params: { contactId?: number; conversationId?: number } = {}): Promise<Note[]> {
  const query = new URLSearchParams()
  query.set("per_page", "100")
  if (params.contactId) query.set("contact_id", String(params.contactId))
  if (params.conversationId) query.set("conversation_id", String(params.conversationId))

  const response = await fetch(`/api/notes?${query.toString()}`, {
    headers: {
      Accept: "application/json",
    },
    cache: "no-store",
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar notas")
  }

  return (payload.data ?? []).map(normalizeNote)
}

export async function createNote(body: CreateNotePayload): Promise<Note> {
  const response = await fetch("/api/notes", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al crear nota")
  }

  return normalizeNote(payload.data)
}

export async function deleteNote(noteId: number): Promise<void> {
  const response = await fetch(`/api/notes/${noteId}`, {
    method: "DELETE",
    headers: {
      Accept: "application/json",
    },
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al eliminar nota")
  }
}
