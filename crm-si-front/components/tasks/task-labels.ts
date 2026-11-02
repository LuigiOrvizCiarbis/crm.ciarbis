import type { TaskPriority, TaskStatus, TaskType } from "@/lib/types/task"

export const statusLabels: Record<TaskStatus, string> = {
  nuevo: "Nuevo",
  "en-curso": "En curso",
  "en-espera": "En espera",
  reprogramado: "Reprogramado",
  bloqueado: "Bloqueado",
  hecho: "Hecho",
  cancelado: "Cancelado",
}

export const priorityLabels: Record<TaskPriority, string> = {
  baja: "Baja",
  media: "Media",
  alta: "Alta",
  critica: "Crítica",
}

export const typeLabels: Record<TaskType, string> = {
  reunion: "Reunión",
  llamado: "Llamado",
  demo: "Demo",
  propuesta: "Propuesta",
  visita: "Visita",
  seguimiento: "Seguimiento",
  soporte: "Soporte",
}
