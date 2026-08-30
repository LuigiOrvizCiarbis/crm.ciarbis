"use client"

import { useState, useEffect } from "react"
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
import type { Task, TaskStatus, TaskType } from "@/lib/types/task"
import { useIsMobile } from "@/hooks/use-mobile"
import type { TaskFilters } from "@/lib/types/task-filters"
import { isSameDay } from "date-fns"

const STATUS_FILTER_OPTIONS: TaskStatus[] = ["nuevo", "en-curso", "bloqueado", "hecho", "cancelado"]
const TYPE_FILTER_OPTIONS: TaskType[] = ["llamado", "reunion", "seguimiento", "soporte", "demo"]
const DEADLINE_FILTER_OPTIONS: { value: NonNullable<TaskFilters["deadline"]>; label: string }[] = [
  { value: "overdue", label: "Vencidas" },
  { value: "today", label: "Hoy" },
  { value: "this-week", label: "Esta semana" },
  { value: "next-7-days", label: "Próximos 7 días" },
  { value: "no-date", label: "Sin fecha" },
]

export default function TareasPage() {
  const tasks = useTaskStore((state) => state.tasks)
  const isLoading = useTaskStore((state) => state.isLoading)
  const error = useTaskStore((state) => state.error)
  const fetchTasks = useTaskStore((state) => state.fetchTasks)
  const [activeView, setActiveView] = useState("lista")
  const [searchQuery, setSearchQuery] = useState("")
  const [filteredTasks, setFilteredTasks] = useState(tasks)
  const [showNewTask, setShowNewTask] = useState(false)
  const [showFilters, setShowFilters] = useState(false)
  const [filters, setFilters] = useState<TaskFilters>({
    status: [],
    assignees: [],
    types: [],
    deadline: null,
  })
  const isMobile = useIsMobile()

  useEffect(() => {
    fetchTasks()
  }, [fetchTasks])

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
    if (filters.assignees.length > 0) {
      filtered = filtered.filter((task) => filters.assignees.includes(task.assignee))
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
        onViewChange={setActiveView}
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
        <SheetContent side="right" className="w-[400px] sm:w-[540px]">
          <SheetHeader>
            <SheetTitle>Filtros</SheetTitle>
            <SheetDescription>Filtra tus tareas por estado, responsable, tipo y fecha</SheetDescription>
          </SheetHeader>

          <div className="py-6 space-y-6">
            {/* Estado */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Estado</Label>
              <div className="space-y-2">
                {STATUS_FILTER_OPTIONS.map((status) => (
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
                    <label htmlFor={`status-${status}`} className="text-sm cursor-pointer capitalize">
                      {status}
                    </label>
                  </div>
                ))}
              </div>
            </div>

            {/* Responsable */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Responsable</Label>
              <div className="space-y-2">
                {["Ana Gómez", "Carlos Ruiz", "María López", "Juan Pérez", "Laura Torres"].map((assignee) => (
                  <div key={assignee} className="flex items-center space-x-2">
                    <Checkbox
                      id={`assignee-${assignee}`}
                      checked={filters.assignees.includes(assignee)}
                      onCheckedChange={(checked) => {
                        if (checked) {
                          setFilters({ ...filters, assignees: [...filters.assignees, assignee] })
                        } else {
                          setFilters({ ...filters, assignees: filters.assignees.filter((a) => a !== assignee) })
                        }
                      }}
                    />
                    <label htmlFor={`assignee-${assignee}`} className="text-sm cursor-pointer">
                      {assignee}
                    </label>
                  </div>
                ))}
              </div>
            </div>

            {/* Tipo */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Tipo</Label>
              <div className="space-y-2">
                {TYPE_FILTER_OPTIONS.map((type) => (
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
                    <label htmlFor={`type-${type}`} className="text-sm cursor-pointer capitalize">
                      {type}
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

          <SheetFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setFilters({ status: [], assignees: [], types: [], deadline: null })
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
