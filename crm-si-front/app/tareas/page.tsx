"use client"

import { useState, useEffect, useRef } from "react"
import { SidebarLayout } from "@/components/SidebarLayout"
import { TasksCompactHeader } from "@/components/tasks-compact-header"
import { Badge } from "@/components/ui/badge"
import { TaskListView } from "@/components/tasks/TaskListView"
import { TaskKanbanView } from "@/components/tasks/TaskKanbanView"
import { TaskGanttView } from "@/components/tasks/TaskGanttView"
import { TaskCalendarView } from "@/components/tasks/TaskCalendarView"
import { NewTaskModal } from "@/components/tasks/NewTaskModal"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from "@/components/ui/sheet"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Button } from "@/components/ui/button"
import { useTaskStore } from "@/store/useTaskStore"
import { getUsers, type SystemUser } from "@/lib/api/users"
import type { Task, TaskStatus, TaskType } from "@/lib/types/task"
import { useIsMobile } from "@/hooks/use-mobile"
import type { StoredTaskView, TaskFilters, TaskViewMode } from "@/lib/types/task-filters"
import { useAuthStore } from "@/store/useAuthStore"
import { updateTaskViewPreferences } from "@/lib/api/profile"
import { isSameDay } from "date-fns"

const STATUS_FILTER_OPTIONS: { value: TaskStatus; label: string }[] = [
  { value: "nuevo", label: "Nuevo" },
  { value: "en-curso", label: "En curso" },
  { value: "en-espera", label: "En espera" },
  { value: "reprogramado", label: "Reprogramado" },
  { value: "bloqueado", label: "Bloqueado" },
  { value: "hecho", label: "Hecho" },
  { value: "cancelado", label: "Cancelado" },
]
const TYPE_FILTER_OPTIONS: { value: TaskType; label: string }[] = [
  { value: "llamado", label: "Llamado" },
  { value: "reunion", label: "Reunión" },
  { value: "seguimiento", label: "Seguimiento" },
  { value: "soporte", label: "Soporte" },
  { value: "demo", label: "Demo" },
  { value: "propuesta", label: "Propuesta" },
  { value: "visita", label: "Visita" },
]
const DEADLINE_FILTER_OPTIONS: { value: NonNullable<TaskFilters["deadline"]>; label: string }[] = [
  { value: "overdue", label: "Vencidas" },
  { value: "today", label: "Hoy" },
  { value: "this-week", label: "Esta semana" },
  { value: "next-7-days", label: "Próximos 7 días" },
  { value: "no-date", label: "Sin fecha" },
]

const EMPTY_FILTERS: TaskFilters = { status: [], assigneeIds: [], types: [], deadline: null }
const VIEW_MODES: TaskViewMode[] = ["lista", "kanban", "gantt", "calendario"]

function toStoredTaskView(view: TaskViewMode, filters: TaskFilters): StoredTaskView {
  return {
    view,
    filters: {
      status: filters.status,
      types: filters.types,
      assignee_ids: filters.assigneeIds,
      deadline: filters.deadline,
    },
  }
}

export default function TareasPage() {
  const tasks = useTaskStore((state) => state.tasks)
  const isLoading = useTaskStore((state) => state.isLoading)
  const error = useTaskStore((state) => state.error)
  const fetchTasks = useTaskStore((state) => state.fetchTasks)
  const [activeView, setActiveView] = useState<TaskViewMode>("lista")
  const [searchQuery, setSearchQuery] = useState("")
  const [filteredTasks, setFilteredTasks] = useState(tasks)
  const [showNewTask, setShowNewTask] = useState(false)
  const [showFilters, setShowFilters] = useState(false)
  const [users, setUsers] = useState<SystemUser[]>([])
  const [filters, setFilters] = useState<TaskFilters>(EMPTY_FILTERS)
  const isMobile = useIsMobile()

  // La vista y los filtros se guardan por usuario y workspace
  // (users.preferences.tasks_view), así sobreviven a recargar o cambiar de sección.
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)
  const tenantKey = user?.tenant_id != null ? String(user.tenant_id) : null
  const hydratedTenantRef = useRef<string | null>(null)
  const lastSavedRef = useRef<string | null>(null)

  useEffect(() => {
    if (!tenantKey || hydratedTenantRef.current === tenantKey) return
    hydratedTenantRef.current = tenantKey

    const stored = user?.preferences?.tasks_view?.[tenantKey]
    if (!stored) {
      lastSavedRef.current = JSON.stringify(toStoredTaskView("lista", EMPTY_FILTERS))
      return
    }

    const view = VIEW_MODES.includes(stored.view) ? stored.view : "lista"
    const restored: TaskFilters = {
      status: stored.filters?.status ?? [],
      types: stored.filters?.types ?? [],
      assigneeIds: stored.filters?.assignee_ids ?? [],
      deadline: stored.filters?.deadline ?? null,
    }
    lastSavedRef.current = JSON.stringify(toStoredTaskView(view, restored))
    setActiveView(view)
    setFilters(restored)
  }, [tenantKey, user?.preferences])

  useEffect(() => {
    if (!tenantKey || hydratedTenantRef.current !== tenantKey) return

    const payload = toStoredTaskView(activeView, filters)
    const serialized = JSON.stringify(payload)
    if (serialized === lastSavedRef.current) return

    const timeout = window.setTimeout(() => {
      lastSavedRef.current = serialized
      updateTaskViewPreferences(payload).then(({ data }) => {
        if (data) updateUser({ preferences: data })
        else lastSavedRef.current = null
      })
    }, 600)

    return () => window.clearTimeout(timeout)
  }, [activeView, filters, tenantKey, updateUser])

  useEffect(() => {
    fetchTasks()
  }, [fetchTasks])

  useEffect(() => {
    getUsers().then(setUsers)
  }, [])

  const hasCalendarSyncInFlight = tasks.some((task) => {
    if (task.type !== "reunion") return false

    if (!task.calendarSync) {
      const updatedAt = task.updatedAt ? new Date(task.updatedAt).getTime() : 0
      return updatedAt > 0 && Date.now() - updatedAt < 120_000
    }

    return task.calendarSync.status === "pending" &&
      ["retrying", "conference_pending"].includes(task.calendarSync.lastError ?? "")
  })

  useEffect(() => {
    if (!hasCalendarSyncInFlight) return

    let inFlight = false
    const interval = window.setInterval(() => {
      if (inFlight) return
      inFlight = true
      void fetchTasks().finally(() => {
        inFlight = false
      })
    }, 10_000)

    return () => window.clearInterval(interval)
  }, [fetchTasks, hasCalendarSyncInFlight])

  useEffect(() => {
    let filtered = tasks

    // Apply search query
    if (searchQuery) {
      filtered = filtered.filter((task) => task.name.toLowerCase().includes(searchQuery.toLowerCase()))
    }

    // Apply status filter
    if (filters.status.length > 0) {
      filtered = filtered.filter((task) => filters.status.includes(task.status))
    }

    // Apply assignee filter
    if (filters.assigneeIds.length > 0) {
      filtered = filtered.filter((task) => task.assignedToId != null && filters.assigneeIds.includes(task.assignedToId))
    }

    // Apply type filter
    if (filters.types.length > 0) {
      filtered = filtered.filter((task) => filters.types.includes(task.type))
    }

    // Apply deadline filter
    if (filters.deadline) {
      const now = new Date()
      const weekFromNow = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000)

      filtered = filtered.filter((task) => {
        if (filters.deadline === "overdue") {
          return (
            task.deadline && new Date(task.deadline) < now && task.status !== "hecho" && task.status !== "cancelado"
          )
        }
        if (filters.deadline === "today") {
          return Boolean(task.deadline && isSameDay(new Date(task.deadline), now) && task.status !== "hecho")
        }
        if (filters.deadline === "this-week") {
          if (!task.deadline || task.status === "hecho" || task.status === "cancelado") return false
          const deadline = new Date(task.deadline)
          return deadline >= now && deadline <= weekFromNow
        }
        if (filters.deadline === "next-7-days") {
          if (!task.deadline || task.status === "hecho" || task.status === "cancelado") return false
          const deadline = new Date(task.deadline)
          const next7Days = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000)
          return deadline >= now && deadline <= next7Days
        }
        if (filters.deadline === "no-date") {
          return !task.deadline
        }
        return true
      })
    }

    setFilteredTasks(filtered)
  }, [searchQuery, filters, tasks])

  // Keyboard shortcuts
  useEffect(() => {
    const handleKeyPress = (e: KeyboardEvent) => {
      // N for new task
      if (e.key === "n" && !e.ctrlKey && !e.metaKey) {
        const target = e.target as HTMLElement
        if (target.tagName !== "INPUT" && target.tagName !== "TEXTAREA") {
          e.preventDefault()
          setShowNewTask(true)
        }
      }
      // / for search focus
      if (e.key === "/") {
        e.preventDefault()
        document.querySelector<HTMLInputElement>('[placeholder="Buscar tareas..."]')?.focus()
      }
      // L for Lista
      if (e.key === "l" && !e.ctrlKey && !e.metaKey) {
        const target = e.target as HTMLElement
        if (target.tagName !== "INPUT" && target.tagName !== "TEXTAREA") {
          setActiveView("lista")
        }
      }
      // K for Kanban
      if (e.key === "k" && !e.ctrlKey && !e.metaKey) {
        const target = e.target as HTMLElement
        if (target.tagName !== "INPUT" && target.tagName !== "TEXTAREA") {
          setActiveView("kanban")
        }
      }
      // G for Gantt
      if (e.key === "g" && !e.ctrlKey && !e.metaKey) {
        const target = e.target as HTMLElement
        if (target.tagName !== "INPUT" && target.tagName !== "TEXTAREA") {
          setActiveView("gantt")
        }
      }
      // C for Calendario
      if (e.key === "c" && !e.ctrlKey && !e.metaKey) {
        const target = e.target as HTMLElement
        if (target.tagName !== "INPUT" && target.tagName !== "TEXTAREA") {
          setActiveView("calendario")
        }
      }
    }

    window.addEventListener("keydown", handleKeyPress)
    return () => window.removeEventListener("keydown", handleKeyPress)
  }, [])

  const handleCreateTask = (task: Partial<Task>) => {
    console.log("[v0] Task created:", task)
  }

  const overdueTasks = tasks.filter((task) => {
    if (!task.deadline) return false
    const deadline = new Date(task.deadline)
    return deadline < new Date() && task.status !== "hecho" && task.status !== "cancelado"
  })

  const todayTasks = tasks.filter((task) => {
    return Boolean(task.deadline && isSameDay(new Date(task.deadline), new Date()) && task.status !== "hecho")
  })

  const weekTasks = tasks.filter((task) => {
    if (!task.deadline) return false
    const now = new Date()
    const weekFromNow = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000)
    const deadline = new Date(task.deadline)
    return deadline >= now && deadline <= weekFromNow && task.status !== "hecho" && task.status !== "cancelado"
  })

  const completedLast7Days = tasks.filter((task) => {
    if (!task.completedAt) return false
    const now = new Date()
    const last7Days = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)
    return task.status === "hecho" && new Date(task.completedAt) >= last7Days
  })

  const onTimePercentage = (() => {
    const totalTasks = tasks.length
    const onTimeTasks = tasks.filter((task) => {
      if (!task.deadline) return task.status === "hecho"
      const deadline = new Date(task.deadline)
      return deadline >= new Date() || task.status === "hecho"
    }).length
    return totalTasks > 0 ? ((onTimeTasks / totalTasks) * 100).toFixed(0) : "0"
  })()

  const handleFilterToggle = () => {
    console.log("[v0] Filter toggle called, current state:", showFilters)
    setShowFilters(!showFilters)
  }

  return (
    <SidebarLayout>
      <TasksCompactHeader
        activeView={activeView}
        onViewChange={(view) => {
          if (VIEW_MODES.includes(view as TaskViewMode)) setActiveView(view as TaskViewMode)
        }}
        onSearch={setSearchQuery}
        onNewTask={() => setShowNewTask(true)}
        onFilterClick={handleFilterToggle}
      />

      <div className="flex gap-2 px-6 py-3 border-b border-border/50 bg-card">
        <Badge variant="outline" className="text-xs gap-1 bg-red-500/10 text-red-400 border-red-500/30">
          <span className="font-bold">{overdueTasks.length}</span> Vencidas
        </Badge>
        <Badge variant="outline" className="text-xs gap-1 bg-orange-500/10 text-orange-400 border-orange-500/30">
          <span className="font-bold">{todayTasks.length}</span> Para hoy
        </Badge>
        <Badge variant="outline" className="text-xs gap-1 bg-blue-500/10 text-blue-400 border-blue-500/30">
          <span className="font-bold">{weekTasks.length}</span> Esta semana
        </Badge>
        <Badge variant="outline" className="text-xs gap-1 bg-emerald-500/10 text-emerald-400 border-emerald-500/30">
          <span className="font-bold">{completedLast7Days.length}</span> Hechas (últimos 7 días)
        </Badge>
        <Badge variant="outline" className="text-xs gap-1 bg-purple-500/10 text-purple-400 border-purple-500/30">
          <span className="font-bold">{onTimePercentage}%</span> A tiempo
        </Badge>
      </div>

      <div className="flex-1 overflow-y-auto p-6">
        {isLoading && <div className="text-sm text-muted-foreground">Cargando tareas...</div>}
        {!isLoading && error && <div className="text-sm text-red-500">{error}</div>}
        {!isLoading && !error && activeView === "lista" && <TaskListView tasks={filteredTasks} />}
        {!isLoading && !error && activeView === "kanban" && <TaskKanbanView tasks={filteredTasks} />}
        {!isLoading && !error && activeView === "gantt" && <TaskGanttView tasks={filteredTasks} />}
        {!isLoading && !error && activeView === "calendario" && <TaskCalendarView tasks={filteredTasks} />}
      </div>

      {/* New Task Modal */}
      <NewTaskModal open={showNewTask} onOpenChange={setShowNewTask} onCreateTask={handleCreateTask} />

      {/* Simple Sheet for Filters */}
      <Sheet open={showFilters} onOpenChange={setShowFilters}>
        <SheetContent side="right" className="w-[400px] sm:w-[540px] gap-0">
          <SheetHeader className="border-b border-border">
            <SheetTitle>Filtros</SheetTitle>
            <SheetDescription>Filtra tus tareas por estado, responsable, tipo y fecha</SheetDescription>
          </SheetHeader>

          <div className="flex-1 min-h-0 overflow-y-auto px-4 py-6 space-y-6">
            {/* Estado */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Estado</Label>
              <div className="space-y-2">
                {STATUS_FILTER_OPTIONS.map(({ value: status, label }) => (
                  <div key={status} className="flex items-center space-x-2">
                    <Checkbox
                      id={`status-${status}`}
                      checked={filters.status.includes(status)}
                      onCheckedChange={(checked) => {
                        if (checked) {
                          setFilters({ ...filters, status: [...filters.status, status] })
                        } else {
                          setFilters({ ...filters, status: filters.status.filter((s) => s !== status) })
                        }
                      }}
                    />
                    <label htmlFor={`status-${status}`} className="text-sm cursor-pointer">
                      {label}
                    </label>
                  </div>
                ))}
              </div>
            </div>

            {/* Responsable */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Responsable</Label>
              <div className="space-y-2">
                {users.map((user) => (
                  <div key={user.id} className="flex items-center space-x-2">
                    <Checkbox
                      id={`assignee-${user.id}`}
                      checked={filters.assigneeIds.includes(user.id)}
                      onCheckedChange={(checked) => {
                        if (checked) {
                          setFilters({ ...filters, assigneeIds: [...filters.assigneeIds, user.id] })
                        } else {
                          setFilters({ ...filters, assigneeIds: filters.assigneeIds.filter((id) => id !== user.id) })
                        }
                      }}
                    />
                    <label htmlFor={`assignee-${user.id}`} className="text-sm cursor-pointer">
                      {user.name}
                    </label>
                  </div>
                ))}
                {users.length === 0 && <p className="text-sm text-muted-foreground">No hay responsables disponibles</p>}
              </div>
            </div>

            {/* Tipo */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Tipo</Label>
              <div className="space-y-2">
                {TYPE_FILTER_OPTIONS.map(({ value: type, label }) => (
                  <div key={type} className="flex items-center space-x-2">
                    <Checkbox
                      id={`type-${type}`}
                      checked={filters.types.includes(type)}
                      onCheckedChange={(checked) => {
                        if (checked) {
                          setFilters({ ...filters, types: [...filters.types, type] })
                        } else {
                          setFilters({ ...filters, types: filters.types.filter((t) => t !== type) })
                        }
                      }}
                    />
                    <label htmlFor={`type-${type}`} className="text-sm cursor-pointer">
                      {label}
                    </label>
                  </div>
                ))}
              </div>
            </div>

            {/* Fecha */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Fecha</Label>
              <div className="space-y-2">
                {DEADLINE_FILTER_OPTIONS.map((deadline) => (
                  <div key={deadline.value} className="flex items-center space-x-2">
                    <Checkbox
                      id={`deadline-${deadline.value}`}
                      checked={filters.deadline === deadline.value}
                      onCheckedChange={(checked) => {
                        setFilters({ ...filters, deadline: checked ? deadline.value : null })
                      }}
                    />
                    <label htmlFor={`deadline-${deadline.value}`} className="text-sm cursor-pointer">
                      {deadline.label}
                    </label>
                  </div>
                ))}
              </div>
            </div>
          </div>

          <SheetFooter className="flex-row justify-end gap-2 border-t border-border bg-background">
            <Button
              variant="outline"
              onClick={() => {
                setFilters(EMPTY_FILTERS)
              }}
            >
              Limpiar
            </Button>
            <Button onClick={() => setShowFilters(false)}>Aplicar</Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </SidebarLayout>
  )
}
