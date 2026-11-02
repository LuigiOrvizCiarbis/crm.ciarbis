import { create } from "zustand"
import type { Task } from "@/lib/types/task"
import {
  approveAiBooking as approveAiBookingRequest,
  approveAiReschedule as approveAiRescheduleRequest,
  createTask as createTaskRequest,
  deleteTask as deleteTaskRequest,
  getTasks,
  rejectAiBooking as rejectAiBookingRequest,
  rejectAiReschedule as rejectAiRescheduleRequest,
  updateTask as updateTaskRequest,
  type TaskPayload,
} from "@/lib/api/tasks"

// Borrados en ventana de "Deshacer": la tarea ya no se ve, pero la API todavía no se llamó.
interface PendingDelete {
  task: Task
  index: number
  resolve: (committed: boolean) => void
  reject: (error: unknown) => void
}

const pendingDeletes = new Map<string, PendingDelete>()

// Vuelve a su lugar original (acotado al largo actual, por si la lista cambió entre tanto).
function restoreTask({ task, index }: PendingDelete) {
  useTaskStore.setState((state) => {
    if (state.tasks.some((item) => item.id === task.id)) return state
    const tasks = [...state.tasks]
    tasks.splice(Math.min(index, tasks.length), 0, task)
    return { tasks }
  })
}

interface TaskStore {
  tasks: Task[]
  isLoading: boolean
  error: string | null
  fetchTasks: () => Promise<void>
  addTask: (task: TaskPayload) => Promise<Task>
  updateTask: (id: string, updates: TaskPayload) => Promise<Task>
  /** Saca la tarea de la lista sin llamar a la API. Resuelve true al confirmarse, false si se deshace; rechaza si la API falla (la tarea vuelve). */
  deleteTask: (id: string) => Promise<boolean>
  undoDelete: (id: string) => void
  commitDelete: (id: string) => Promise<void>
  flushPendingDeletes: () => void
  approveAiBooking: (id: string) => Promise<boolean>
  rejectAiBooking: (id: string) => Promise<boolean>
  approveAiReschedule: (id: string) => Promise<boolean>
  rejectAiReschedule: (id: string) => Promise<boolean>
  getTasks: () => Task[]
}

export const useTaskStore = create<TaskStore>()((set, get) => ({
  tasks: [],
  isLoading: false,
  error: null,

  fetchTasks: async () => {
    set({ isLoading: true, error: null })
    try {
      const tasks = await getTasks()
      set({ tasks: tasks.filter((task) => !pendingDeletes.has(task.id)), isLoading: false })
    } catch (error) {
      set({ error: error instanceof Error ? error.message : "Error al cargar tareas", isLoading: false })
    }
  },

  addTask: async (task: TaskPayload) => {
    const created = await createTaskRequest(task)
    set((state) => ({ tasks: [created, ...state.tasks] }))
    return created
  },

  updateTask: async (id: string, updates: TaskPayload) => {
    try {
      const updated = await updateTaskRequest(id, updates)
      set((state) => ({
        tasks: state.tasks.map((task) => (task.id === id ? updated : task)),
      }))
      return updated
    } catch (error) {
      throw error
    }
  },

  deleteTask: (id: string) => {
    const { tasks } = get()
    const index = tasks.findIndex((task) => task.id === id)
    if (index === -1 || pendingDeletes.has(id)) return Promise.resolve(false)

    const { promise, resolve, reject } = Promise.withResolvers<boolean>()
    pendingDeletes.set(id, { task: tasks[index], index, resolve, reject })
    set({ tasks: tasks.filter((task) => task.id !== id) })
    return promise
  },

  undoDelete: (id: string) => {
    const pending = pendingDeletes.get(id)
    if (!pending) return
    pendingDeletes.delete(id)
    restoreTask(pending)
    pending.resolve(false)
  },

  commitDelete: async (id: string) => {
    const pending = pendingDeletes.get(id)
    if (!pending) return
    pendingDeletes.delete(id)
    try {
      await deleteTaskRequest(id)
      pending.resolve(true)
    } catch (error) {
      restoreTask(pending)
      pending.reject(error)
    }
  },

  flushPendingDeletes: () => {
    for (const id of Array.from(pendingDeletes.keys())) void get().commitDelete(id)
  },

  approveAiBooking: async (id: string) => {
    const { task: updated, contactNotified } = await approveAiBookingRequest(id)
    set((state) => ({
      tasks: state.tasks.map((task) => (task.id === id ? updated : task)),
    }))
    return contactNotified
  },

  rejectAiBooking: async (id: string) => {
    const { task: updated, contactNotified } = await rejectAiBookingRequest(id)
    set((state) => ({
      tasks: state.tasks.map((task) => (task.id === id ? updated : task)),
    }))
    return contactNotified
  },

  approveAiReschedule: async (id: string) => {
    const { task: updated, contactNotified } = await approveAiRescheduleRequest(id)
    set((state) => ({
      tasks: state.tasks.map((task) => (task.id === id ? updated : task)),
    }))
    return contactNotified
  },

  rejectAiReschedule: async (id: string) => {
    const { task: updated, contactNotified } = await rejectAiRescheduleRequest(id)
    set((state) => ({
      tasks: state.tasks.map((task) => (task.id === id ? updated : task)),
    }))
    return contactNotified
  },

  getTasks: () => get().tasks,
}))
