import { throwApiError } from "./api-error"
import { getAuthToken } from "./auth-token"
import type { Task } from "@/lib/types/task"

export interface TaskPayload {
  name?: string
  status?: Task["status"]
  priority?: Task["priority"]
  type?: Task["type"]
  deadline?: string | null
  start_date?: string | null
  description?: string | null
  assigned_to?: number | null
  contact_id?: number | null
  conversation_id?: number | null
  opportunity_id?: number | null
  reminders?: Task["reminders"]
  recurrence?: string | null
  depends_on?: string[]
  checklist?: Task["checklist"]
  attachments?: Task["attachments"]
  synced_calendars?: string[]
  completed_at?: string | null
  starts_at?: string | null
  ends_at?: string | null
  meeting_timezone?: string | null
  meeting_guest_email?: string | null
}

function requireToken() {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")
  return token
}

function relationFromApi(apiTask: any): Task["relatedTo"] {
  if (apiTask.opportunity_id || apiTask.opportunity) {
    return {
      kind: "pipeline",
      id: String(apiTask.opportunity_id ?? apiTask.opportunity?.id),
      label: apiTask.opportunity?.title ?? "Oportunidad",
    }
  }

  if (apiTask.conversation_id || apiTask.conversation) {
    return {
      kind: "chat",
      id: String(apiTask.conversation_id ?? apiTask.conversation?.id),
      label: apiTask.conversation?.contact?.name ?? apiTask.conversation?.last_message_content ?? "Chat",
    }
  }

  if (apiTask.contact_id || apiTask.contact) {
    return {
      kind: "contact",
      id: String(apiTask.contact_id ?? apiTask.contact?.id),
      label: apiTask.contact?.name ?? "Contacto",
    }
  }

  return undefined
}

export function normalizeTask(apiTask: any): Task {
  return {
    id: String(apiTask.id),
    name: apiTask.name,
    status: apiTask.status,
    priority: apiTask.priority,
    type: apiTask.type,
    deadline: apiTask.deadline ?? undefined,
    startDate: apiTask.start_date ?? undefined,
    assignee: apiTask.assigned_user?.name ?? "Sin asignar",
    assignedToId: apiTask.assigned_to ?? null,
    relatedTo: relationFromApi(apiTask),
    reminders: apiTask.reminders ?? [],
    recurrence: apiTask.recurrence ?? undefined,
    dependsOn: apiTask.depends_on ?? [],
    checklist: apiTask.checklist ?? [],
    attachments: apiTask.attachments ?? [],
    description: apiTask.description ?? undefined,
    createdAt: apiTask.created_at,
    updatedAt: apiTask.updated_at,
    completedAt: apiTask.completed_at ?? undefined,
    syncedCalendars: apiTask.synced_calendars ?? [],
    startsAt: apiTask.starts_at ?? undefined,
    endsAt: apiTask.ends_at ?? undefined,
    meetingTimezone: apiTask.meeting_timezone ?? undefined,
    meetingGuestEmail: apiTask.meeting_guest_email ?? undefined,
    calendarSync: apiTask.calendar_sync
      ? {
          status: apiTask.calendar_sync.status,
          htmlLink: apiTask.calendar_sync.html_link ?? null,
          meetLink: apiTask.calendar_sync.meet_link ?? null,
          lastError: apiTask.calendar_sync.last_error ?? null,
          syncedAt: apiTask.calendar_sync.synced_at ?? null,
        }
      : null,
  }
}

export async function getTasks(): Promise<Task[]> {
  const token = requireToken()

  const response = await fetch("/api/tasks?per_page=100", {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar tareas")
  }

  return (payload.data ?? []).map(normalizeTask)
}

export async function createTask(body: TaskPayload): Promise<Task> {
  const token = requireToken()

  const response = await fetch("/api/tasks", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al crear tarea")
  }

  return normalizeTask(payload.data)
}

export async function updateTask(taskId: string, body: TaskPayload): Promise<Task> {
  const token = requireToken()

  const response = await fetch(`/api/tasks/${taskId}`, {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar tarea")
  }

  return normalizeTask(payload.data)
}

export async function deleteTask(taskId: string): Promise<void> {
  const token = requireToken()

  const response = await fetch(`/api/tasks/${taskId}`, {
    method: "DELETE",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al eliminar tarea")
  }
}
