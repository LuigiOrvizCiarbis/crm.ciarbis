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

interface TaskStore {
  tasks: Task[]
  isLoading: boolean
  error: string | null
  fetchTasks: () => Promise<void>
  addTask: (task: TaskPayload) => Promise<Task>
  updateTask: (id: string, updates: TaskPayload) => Promise<Task>
  deleteTask: (id: string) => Promise<void>
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
      set({ tasks, isLoading: false })
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

  deleteTask: async (id: string) => {
    const previousTasks = get().tasks
    set((state) => ({ tasks: state.tasks.filter((task) => task.id !== id) }))

    try {
      await deleteTaskRequest(id)
    } catch (error) {
      set({ tasks: previousTasks })
      throw error
    }
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
