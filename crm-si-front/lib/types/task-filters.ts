import type { TaskStatus, TaskType } from "@/lib/types/task"

export type TaskViewMode = "lista" | "kanban" | "gantt" | "calendario"

/** Lo que se guarda en users.preferences.tasks_view[tenant_id] (snake_case, como el backend). */
export interface StoredTaskView {
  view: TaskViewMode
  filters: {
    status: TaskStatus[]
    types: TaskType[]
    assignee_ids: number[]
    deadline: TaskFilters["deadline"]
  }
}

export interface TaskFilters {
  status: TaskStatus[]
  assigneeIds: number[]
  types: TaskType[]
  deadline: "overdue" | "today" | "this-week" | "next-7-days" | "no-date" | null
}
