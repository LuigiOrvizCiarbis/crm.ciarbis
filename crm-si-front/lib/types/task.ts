export type TaskStatus = "nuevo" | "en-curso" | "en-espera" | "reprogramado" | "bloqueado" | "hecho" | "cancelado"
export type TaskPriority = "baja" | "media" | "alta" | "critica"
export type TaskType = "reunion" | "llamado" | "demo" | "propuesta" | "visita" | "seguimiento" | "soporte"

export interface TaskReminder {
  at: string // ISO date
  channel: "inapp" | "email" | "push"
}

export interface TaskChecklist {
  id: string
  text: string
  done: boolean
}

export interface TaskAttachment {
  id: string
  name: string
  url: string
}

export interface TaskRelation {
  kind: "contact" | "pipeline" | "chat"
  id: string
  label: string
}

export type TaskCalendarSyncStatus = "pending" | "synced" | "error" | "paused"

export interface TaskCalendarSyncInfo {
  status: TaskCalendarSyncStatus
  htmlLink: string | null
  meetLink: string | null
  lastError: string | null
  syncedAt: string | null
}

export interface Task {
  id: string
  name: string
  status: TaskStatus
  priority: TaskPriority
  type: TaskType
  deadline?: string // ISO date
  startDate?: string // ISO date — inicio planificado (Gantt)
  assignee: string
  assignedToId?: number | null
  relatedTo?: TaskRelation
  reminders: TaskReminder[]
  recurrence?: string // RRULE string
  dependsOn: string[] // task ids
  checklist: TaskChecklist[]
  attachments: TaskAttachment[]
  description?: string
  createdAt: string
  updatedAt: string
  completedAt?: string
  syncedCalendars: string[] // ["google", "outlook", "icloud", "caldav"]
  // Reunión (Google Calendar)
  startsAt?: string // ISO date
  endsAt?: string // ISO date
  meetingTimezone?: string // IANA tz
  meetingGuestEmail?: string
  calendarSync?: TaskCalendarSyncInfo | null
}
