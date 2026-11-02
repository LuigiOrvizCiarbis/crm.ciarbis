"use client"

import { useState, useEffect, useCallback } from "react"
import { SidebarLayout } from "@/components/SidebarLayout"
import { TasksCompactHeader } from "@/components/tasks-compact-header"
import { Badge } from "@/components/ui/badge"
import { TaskListView } from "@/components/tasks/TaskListView"
import { TaskKanbanView } from "@/components/tasks/TaskKanbanView"
import { TaskGanttView } from "@/components/tasks/TaskGanttView"
import { TaskCalendarView } from "@/components/tasks/TaskCalendarView"
import { NewTaskModal } from "@/components/tasks/NewTaskModal"
import { CalendarAvailabilityBlockDialog } from "@/components/calendar/CalendarAvailabilityBlockDialog"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from "@/components/ui/sheet"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { ListTodo, Search, SearchX, X } from "lucide-react"
import { EmptyState } from "@/components/EmptyState"
import { Skeleton } from "@/components/ui/skeleton"
import { statusLabels, typeLabels } from "@/components/tasks/task-labels"
import { useTaskStore } from "@/store/useTaskStore"
import { fetchUsers, type SystemUser } from "@/lib/api/users"
import type { TaskStatus, TaskType } from "@/lib/types/task"
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
  const flushPendingDeletes = useTaskStore((state) => state.flushPendingDeletes)
  const [activeView, setActiveView] = useState("lista")
  const [searchQuery, setSearchQuery] = useState("")
  const [filteredTasks, setFilteredTasks] = useState(tasks)
  const [showNewTask, setShowNewTask] = useState(false)
  const [calendarBlockDialog, setCalendarBlockDialog] = useState<{ open: boolean; initialDate?: string }>({ open: false })
  const [calendarRefreshKey, setCalendarRefreshKey] = useState(0)
  const [showFilters, setShowFilters] = useState(false)
  const [users, setUsers] = useState<SystemUser[]>([])
  const [usersError, setUsersError] = useState(false)
  const [usersLoading, setUsersLoading] = useState(true)
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

  // Los borrados en ventana de "Deshacer" se confirman al salir de la página: la intención no se pierde.
  useEffect(() => {
    window.addEventListener("pagehide", flushPendingDeletes)
    return () => {
      window.removeEventListener("pagehide", flushPendingDeletes)
      flushPendingDeletes()
    }
  }, [flushPendingDeletes])

  const loadUsers = useCallback(() => {
    setUsersLoading(true)
    setUsersError(false)
    fetchUsers()
      .then(setUsers)
      .catch(() => setUsersError(true))
      .finally(() => setUsersLoading(false))
  }, [])

  useEffect(() => {
    loadUsers()
  }, [loadUsers])

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
    setShowFilters(!showFilters)
  }

  const hasActiveFilters =
    Boolean(searchQuery) ||
    filters.status.length > 0 ||
    filters.assignees.length > 0 ||
    filters.types.length > 0 ||
    filters.deadline !== null

  const clearFilters = () => {
    setSearchQuery("")
    setFilters({ status: [], assignees: [], types: [], deadline: null })
  }

  // El sondeo de sincronización vuelve a llamar fetchTasks: el esqueleto sólo va en la primera carga.
  const showInitialLoading = isLoading && tasks.length === 0
  const showViews = !showInitialLoading && !(error && tasks.length === 0)
  // El calendario sigue útil vacío (eventos de Google, bloquear agenda); las otras vistas no.
  const showEmpty = showViews && activeView !== "calendario" && filteredTasks.length === 0

  return (
    <SidebarLayout>
      <TasksCompactHeader
        activeView={activeView}
        onViewChange={setActiveView}
        searchValue={searchQuery}
        onSearch={setSearchQuery}
        onNewTask={() => setShowNewTask(true)}
        onFilterClick={handleFilterToggle}
      />

      <div className="border-b border-border/50 bg-card">
        {/* El buscador del header se oculta bajo md: en teléfonos va a ancho completo acá. */}
        <div className="px-4 pt-3 md:hidden">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <Input
              value={searchQuery}
              onChange={(event) => setSearchQuery(event.target.value)}
              placeholder="Buscar tareas..."
              aria-label="Buscar tareas"
              autoComplete="off"
              enterKeyHint="search"
              className="h-11 pl-9 pr-11 text-base"
            />
            {searchQuery && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute right-0.5 top-1/2 size-10 -translate-y-1/2 text-muted-foreground"
                onClick={() => setSearchQuery("")}
                aria-label="Limpiar búsqueda"
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </Button>
            )}
          </div>
        </div>

        {/* En teléfonos los resúmenes son una fila deslizable con snap; el fundido derecho indica que hay más. */}
        <div className="flex snap-x snap-mandatory scroll-px-4 gap-2 overflow-x-auto py-3 pl-4 pr-6 [mask-image:linear-gradient(to_right,#000_calc(100%-1.5rem),transparent)] [scrollbar-width:none] md:snap-none md:overflow-visible md:pl-6 md:pr-6 md:[mask-image:none]">
          <Badge variant="outline" className="shrink-0 snap-start text-xs gap-1 bg-destructive/10 text-destructive border-destructive/30 max-md:py-1">
            <span className="font-bold">{overdueTasks.length}</span> Vencidas
          </Badge>
          <Badge variant="outline" className="shrink-0 snap-start text-xs gap-1 bg-warning/10 text-warning border-warning/30 max-md:py-1">
            <span className="font-bold">{todayTasks.length}</span> Para hoy
          </Badge>
          <Badge variant="outline" className="shrink-0 snap-start text-xs gap-1 bg-info/10 text-info border-info/30 max-md:py-1">
            <span className="font-bold">{weekTasks.length}</span> Esta semana
          </Badge>
          <Badge variant="outline" className="shrink-0 snap-start text-xs gap-1 bg-success/10 text-success border-success/30 max-md:py-1">
            <span className="font-bold">{completedLast7Days.length}</span> Hechas (últimos 7 días)
          </Badge>
          <Badge variant="outline" className="shrink-0 snap-start text-xs gap-1 bg-muted text-foreground border-border max-md:py-1">
            <span className="font-bold">{onTimePercentage}%</span> A tiempo
          </Badge>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto p-4 md:p-6">
        {error && (
          <div role="alert" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
            <div className="min-w-0 text-sm">
              <p className="font-medium text-destructive">No se pudieron cargar las tareas.</p>
              <p className="text-muted-foreground">{error}</p>
            </div>
            <Button variant="outline" size="sm" onClick={() => void fetchTasks()} disabled={isLoading}>
              {isLoading ? "Reintentando…" : "Reintentar"}
            </Button>
          </div>
        )}
        {showInitialLoading && (
          <div role="status" aria-label="Cargando tareas" className="space-y-2">
            {Array.from({ length: 6 }).map((_, index) => (
              <Skeleton key={index} className="h-14 w-full rounded-lg" />
            ))}
          </div>
        )}
        {showEmpty &&
          (tasks.length > 0 && hasActiveFilters ? (
            <EmptyState
              icon={SearchX}
              title="Ninguna tarea coincide"
              description="Probá con otra búsqueda o limpiá los filtros para ver todas las tareas."
              action={{ label: "Limpiar filtros", onClick: clearFilters }}
            />
          ) : (
            <EmptyState
              icon={ListTodo}
              title="Todavía no hay tareas"
              description="Creá la primera para organizar llamados, reuniones y seguimientos."
              action={{ label: "Nueva tarea", onClick: () => setShowNewTask(true) }}
            />
          ))}
        {showViews && !showEmpty && activeView === "lista" && <TaskListView tasks={filteredTasks} />}
        {showViews && !showEmpty && activeView === "kanban" && <TaskKanbanView tasks={filteredTasks} />}
        {showViews && !showEmpty && activeView === "gantt" && <TaskGanttView tasks={filteredTasks} />}
        {showViews && activeView === "calendario" && (
          <TaskCalendarView
            tasks={filteredTasks}
            onBlockAgenda={(date) => setCalendarBlockDialog({ open: true, initialDate: date })}
            googleRefreshKey={calendarRefreshKey}
          />
        )}
      </div>

      {/* El store inserta la tarea creada, así que la vista se actualiza sola. */}
      <NewTaskModal open={showNewTask} onOpenChange={setShowNewTask} onCreateTask={() => undefined} />
      <CalendarAvailabilityBlockDialog
        open={calendarBlockDialog.open}
        onOpenChange={(open) => {
          setCalendarBlockDialog((current) => ({ ...current, open }))
          if (!open) setCalendarRefreshKey((k) => k + 1)
        }}
        initialDate={calendarBlockDialog.initialDate}
      />

      {/* Simple Sheet for Filters */}
      <Sheet open={showFilters} onOpenChange={setShowFilters}>
        {/* En teléfonos, hoja inferior: el panel lateral de 400px no entra en 390px. */}
        <SheetContent side={isMobile ? "bottom" : "right"} className={isMobile ? undefined : "w-[400px] sm:w-[540px]"}>
          <SheetHeader>
            <SheetTitle>Filtros</SheetTitle>
            <SheetDescription>Filtrá tus tareas por estado, responsable, tipo y fecha.</SheetDescription>
          </SheetHeader>

          <div className="px-4 py-6 space-y-6 max-md:py-2">
            {/* Estado */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Estado</Label>
              <div className="space-y-2">
                {STATUS_FILTER_OPTIONS.map((status) => (
                  <div key={status} className="flex items-center space-x-2 max-md:min-h-10">
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
                    <label htmlFor={`status-${status}`} className="text-sm cursor-pointer max-md:flex-1 max-md:py-2.5">
                      {statusLabels[status]}
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
                  <div key={user.id} className="flex items-center space-x-2 max-md:min-h-10">
                    <Checkbox
                      id={`assignee-${user.id}`}
                      checked={filters.assignees.includes(user.name)}
                      onCheckedChange={(checked) => {
                        if (checked) {
                          setFilters({ ...filters, assignees: [...filters.assignees, user.name] })
                        } else {
                          setFilters({ ...filters, assignees: filters.assignees.filter((a) => a !== user.name) })
                        }
                      }}
                    />
                    <label htmlFor={`assignee-${user.id}`} className="text-sm cursor-pointer max-md:flex-1 max-md:py-2.5">
                      {user.name}
                    </label>
                  </div>
                ))}
                {usersError && (
                  <div role="alert" className="flex flex-wrap items-center gap-2">
                    <p className="text-sm text-destructive">No se pudieron cargar los responsables.</p>
                    <Button variant="outline" size="sm" onClick={loadUsers} disabled={usersLoading}>
                      {usersLoading ? "Reintentando…" : "Reintentar"}
                    </Button>
                  </div>
                )}
                {!usersError && !usersLoading && users.length === 0 && (
                  <p className="text-sm text-muted-foreground">No hay responsables disponibles</p>
                )}
              </div>
            </div>

            {/* Tipo */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Tipo</Label>
              <div className="space-y-2">
                {TYPE_FILTER_OPTIONS.map((type) => (
                  <div key={type} className="flex items-center space-x-2 max-md:min-h-10">
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
                    <label htmlFor={`type-${type}`} className="text-sm cursor-pointer max-md:flex-1 max-md:py-2.5">
                      {typeLabels[type]}
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
                  <div key={deadline.value} className="flex items-center space-x-2 max-md:min-h-10">
                    <Checkbox
                      id={`deadline-${deadline.value}`}
                      checked={filters.deadline === deadline.value}
                      onCheckedChange={(checked) => {
                        setFilters({ ...filters, deadline: checked ? deadline.value : null })
                      }}
                    />
                    <label htmlFor={`deadline-${deadline.value}`} className="text-sm cursor-pointer max-md:flex-1 max-md:py-2.5">
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
