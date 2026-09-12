import { throwApiError } from "./api-error"
import { getAuthToken, workspaceHeaders } from "./auth-token"

export type TimelineEventType = "note" | "task" | "message" | "stage"

export interface TimelineBaseEvent {
  id: string
  occurredAt: string
}

export interface TimelineNoteEvent extends TimelineBaseEvent {
  type: "note"
  body: string
  author: string | null
}

export interface TimelineTaskEvent extends TimelineBaseEvent {
  type: "task"
  name: string
  taskType: string | null
  status: string | null
  deadline: string | null
  assignee: string | null
}

export interface TimelineMessageEvent extends TimelineBaseEvent {
  type: "message"
  direction: string | null
  content: string
  channel: string | null
}

export interface TimelineStageEvent extends TimelineBaseEvent {
  type: "stage"
  title: string
  stage: string | null
  status: string | null
}

export type TimelineEvent =
  | TimelineNoteEvent
  | TimelineTaskEvent
  | TimelineMessageEvent
  | TimelineStageEvent

function requireToken() {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")
  return token
}

function normalizeEvent(raw: any): TimelineEvent {
  const base = { id: String(raw.id), occurredAt: raw.occurred_at }

  switch (raw.type) {
    case "note":
      return { ...base, type: "note", body: raw.body, author: raw.author ?? null }
    case "task":
      return {
        ...base,
        type: "task",
        name: raw.name,
        taskType: raw.task_type ?? null,
        status: raw.status ?? null,
        deadline: raw.deadline ?? null,
        assignee: raw.assignee ?? null,
      }
    case "message":
      return {
        ...base,
        type: "message",
        direction: raw.direction ?? null,
        content: raw.content ?? "",
        channel: raw.channel ?? null,
      }
    case "stage":
    default:
      return {
        ...base,
        type: "stage",
        title: raw.title ?? "",
        stage: raw.stage ?? null,
        status: raw.status ?? null,
      }
  }
}

export async function getContactTimeline(
  contactId: number,
  types?: TimelineEventType[],
): Promise<TimelineEvent[]> {
  const token = requireToken()

  const query = new URLSearchParams()
  if (types && types.length > 0) query.set("types", types.join(","))

  const suffix = query.toString() ? `?${query.toString()}` : ""

  const response = await fetch(`/api/contacts/${contactId}/timeline${suffix}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      ...workspaceHeaders(),
    },
    cache: "no-store",
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar la línea de tiempo")
  }

  return (payload.data ?? []).map(normalizeEvent)
}
