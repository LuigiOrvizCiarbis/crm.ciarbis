"use client"

import { useEffect, useMemo, useRef, useState } from "react"
import type React from "react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Checkbox } from "@/components/ui/checkbox"
import { Switch } from "@/components/ui/switch"
import { CalendarDays, CalendarClock } from "lucide-react"
import type { Task } from "@/lib/types/task"
import type { TaskPayload } from "@/lib/api/tasks"
import { getUsers, type SystemUser } from "@/lib/api/users"
import {
  addDays,
  differenceInCalendarDays,
  eachDayOfInterval,
  eachMonthOfInterval,
  eachWeekOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  getDaysInMonth,
  isWeekend,
  max as maxDate,
  min as minDate,
  startOfDay,
  startOfMonth,
  startOfWeek,
} from "date-fns"
import { es } from "date-fns/locale"
import { toast } from "sonner"
import { useTaskStore } from "@/store/useTaskStore"

type ZoomLevel = "day" | "week" | "month"
type DragMode = "move" | "start" | "end"

interface DragState {
  taskId: string
  mode: DragMode
  originX: number
  deltaDays: number
}

interface TaskRange {
  start: Date
  end: Date
}

const PX_PER_DAY: Record<ZoomLevel, number> = { day: 44, week: 18, month: 5 }
const NAME_COLUMN_WIDTH = 256
const ROW_HEIGHT = 48
const WEEK_OPTIONS = { weekStartsOn: 1 as const }

const priorityBarColors: Record<Task["priority"], string> = {
  baja: "bg-slate-500",
  media: "bg-blue-500",
  alta: "bg-orange-500",
  critica: "bg-red-500",
}

const statusLabels: Record<Task["status"], string> = {
  nuevo: "Nuevo",
  "en-curso": "En curso",
  "en-espera": "En espera",
  reprogramado: "Reprogramado",
  bloqueado: "Bloqueado",
  hecho: "Hecho",
  cancelado: "Cancelado",
}

const isClosed = (task: Task) => task.status === "hecho" || task.status === "cancelado"

/**
 * Rango que ocupa la barra. Las reuniones usan su inicio/fin reales; el resto
 * va del inicio planificado al vencimiento (sin inicio, es una barra de un día).
 */
function getTaskRange(task: Task): TaskRange | null {
  if (task.type === "reunion" && task.startsAt) {
    const start = new Date(task.startsAt)
    return { start, end: task.endsAt ? new Date(task.endsAt) : start }
  }

  const end = task.deadline ? new Date(task.deadline) : task.startDate ? new Date(task.startDate) : null
  if (!end) return null

  const start = task.startDate ? new Date(task.startDate) : end
  return { start: start > end ? end : start, end }
}

function applyDrag(range: TaskRange, mode: DragMode, deltaDays: number): TaskRange {
  if (deltaDays === 0) return range
  if (mode === "move") return { start: addDays(range.start, deltaDays), end: addDays(range.end, deltaDays) }
  if (mode === "start") {
    const start = addDays(range.start, deltaDays)
    return { start: startOfDay(start) > startOfDay(range.end) ? range.end : start, end: range.end }
  }
  const end = addDays(range.end, deltaDays)
  return { start: range.start, end: startOfDay(end) < startOfDay(range.start) ? range.start : end }
}

function toDateInput(value?: string) {
  return value ? format(new Date(value), "yyyy-MM-dd") : ""
}

function toDateTimeInput(value?: string) {
  return value ? format(new Date(value), "yyyy-MM-dd'T'HH:mm") : ""
}

export function TaskGanttView({ tasks }: { tasks: Task[] }) {
  const updateTask = useTaskStore((state) => state.updateTask)
  const [zoom, setZoom] = useState<ZoomLevel>("week")
  const [showClosed, setShowClosed] = useState(false)
  const [localTasks, setLocalTasks] = useState(tasks)
  const [users, setUsers] = useState<SystemUser[]>([])
  const [editingTask, setEditingTask] = useState<Task | null>(null)
  const [drag, setDrag] = useState<DragState | null>(null)
  const scrollRef = useRef<HTMLDivElement>(null)
  const dragRef = useRef<DragState | null>(null)

  useEffect(() => {
    setLocalTasks(tasks)
  }, [tasks])

  useEffect(() => {
    getUsers().then(setUsers)
  }, [])

  const pxPerDay = PX_PER_DAY[zoom]
  const todayKey = startOfDay(new Date()).getTime()
  const today = useMemo(() => new Date(todayKey), [todayKey])

  const visibleTasks = useMemo(
    () => localTasks.filter((task) => showClosed || !isClosed(task)),
    [localTasks, showClosed],
  )

  const scheduled = useMemo(
    () =>
      visibleTasks
        .map((task) => ({ task, range: getTaskRange(task) }))
        .filter((row): row is { task: Task; range: TaskRange } => row.range !== null)
        .sort((a, b) => a.range.start.getTime() - b.range.start.getTime()),
    [visibleTasks],
  )

  const unscheduled = visibleTasks.filter((task) => !getTaskRange(task))

  // El rango visible se adapta a las tareas, siempre con margen alrededor de hoy.
  const { rangeStart, rangeEnd } = useMemo(() => {
    const starts = scheduled.map((row) => addDays(row.range.start, -3))
    const ends = scheduled.map((row) => addDays(row.range.end, 7))
    const rawStart = startOfDay(minDate([addDays(today, -7), ...starts]))
    const rawEnd = startOfDay(maxDate([addDays(today, 30), ...ends]))

    if (zoom === "month") return { rangeStart: startOfMonth(rawStart), rangeEnd: startOfDay(endOfMonth(rawEnd)) }
    if (zoom === "week") {
      return {
        rangeStart: startOfWeek(rawStart, WEEK_OPTIONS),
        rangeEnd: startOfDay(endOfWeek(rawEnd, WEEK_OPTIONS)),
      }
    }
    return { rangeStart: rawStart, rangeEnd: rawEnd }
  }, [scheduled, zoom, today])

  const totalDays = differenceInCalendarDays(rangeEnd, rangeStart) + 1
  const timelineWidth = totalDays * pxPerDay
  const offsetFor = (date: Date) => differenceInCalendarDays(date, rangeStart) * pxPerDay
  const todayOffset = offsetFor(today)

  const groupHeaders = useMemo(() => {
    const interval = { start: rangeStart, end: rangeEnd }
    if (zoom === "month") {
      const years = new Map<number, number>()
      eachDayOfInterval(interval).forEach((day) => years.set(day.getFullYear(), (years.get(day.getFullYear()) ?? 0) + 1))
      return Array.from(years, ([year, days]) => ({ key: String(year), label: String(year), width: days * pxPerDay }))
    }
    return eachMonthOfInterval(interval).map((month) => {
      const from = maxDate([startOfMonth(month), rangeStart])
      const to = minDate([startOfDay(endOfMonth(month)), rangeEnd])
      return {
        key: month.toISOString(),
        label: format(month, "MMMM yyyy", { locale: es }),
        width: (differenceInCalendarDays(to, from) + 1) * pxPerDay,
      }
    })
  }, [rangeStart, rangeEnd, zoom, pxPerDay])

  const unitHeaders = useMemo(() => {
    const interval = { start: rangeStart, end: rangeEnd }
    if (zoom === "day") {
      return eachDayOfInterval(interval).map((day) => ({
        key: day.toISOString(),
        label: format(day, "EEE d", { locale: es }),
        width: pxPerDay,
        isToday: differenceInCalendarDays(day, today) === 0,
        isWeekend: isWeekend(day),
      }))
    }
    if (zoom === "week") {
      return eachWeekOfInterval(interval, WEEK_OPTIONS).map((week) => ({
        key: week.toISOString(),
        label: format(week, "d MMM", { locale: es }),
        width: 7 * pxPerDay,
        isToday: today >= week && today <= endOfWeek(week, WEEK_OPTIONS),
        isWeekend: false,
      }))
    }
    return eachMonthOfInterval(interval).map((month) => ({
      key: month.toISOString(),
      label: format(month, "MMM", { locale: es }),
      width: getDaysInMonth(month) * pxPerDay,
      isToday: month.getMonth() === today.getMonth() && month.getFullYear() === today.getFullYear(),
      isWeekend: false,
    }))
  }, [rangeStart, rangeEnd, zoom, pxPerDay, today])

  const scrollToToday = (behavior: ScrollBehavior = "smooth") => {
    const container = scrollRef.current
    if (!container) return
    container.scrollTo({ left: Math.max(0, todayOffset - container.clientWidth / 3), behavior })
  }

  useEffect(() => {
    scrollToToday("auto")
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [zoom])

  const persist = (task: Task, optimistic: Partial<Task>, payload: TaskPayload, successMessage: string) => {
    const previousTasks = localTasks
    setLocalTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, ...optimistic } : t)))
    updateTask(task.id, payload).then(
      () => toast.success(successMessage),
      (error) => {
        setLocalTasks(previousTasks)
        toast.error(error instanceof Error ? error.message : "No se pudo actualizar la tarea")
      },
    )
  }

  const commitDrag = (task: Task, mode: DragMode, deltaDays: number) => {
    const range = getTaskRange(task)
    if (!range || deltaDays === 0) return

    if (task.type === "reunion") {
      const startsAt = addDays(range.start, deltaDays).toISOString()
      const endsAt = addDays(range.end, deltaDays).toISOString()
      persist(
        task,
        { startsAt, endsAt, deadline: startsAt },
        { starts_at: startsAt, ends_at: endsAt },
        "Reunión reprogramada",
      )
      return
    }

    const next = applyDrag(range, mode, deltaDays)
    const deadline = next.end.toISOString()
    // Una barra de un día sin inicio propio sigue sin inicio al moverla.
    const startDate = mode === "move" && !task.startDate ? undefined : next.start.toISOString()
    persist(
      task,
      { deadline, startDate },
      { deadline, start_date: startDate ?? null },
      mode === "move" ? "Tarea reprogramada" : "Fechas actualizadas",
    )
  }

  useEffect(() => {
    if (!drag) return

    const handleMove = (event: PointerEvent) => {
      const current = dragRef.current
      if (!current) return
      const deltaDays = Math.round((event.clientX - current.originX) / pxPerDay)
      if (deltaDays !== current.deltaDays) {
        dragRef.current = { ...current, deltaDays }
        setDrag(dragRef.current)
      }
    }

    const handleUp = (event: PointerEvent) => {
      const current = dragRef.current
      dragRef.current = null
      setDrag(null)
      if (!current) return

      const task = localTasks.find((t) => t.id === current.taskId)
      if (!task) return

      // Un click sin arrastre abre el editor.
      if (Math.abs(event.clientX - current.originX) < 4 && current.mode === "move") {
        setEditingTask(task)
        return
      }
      commitDrag(task, current.mode, current.deltaDays)
    }

    window.addEventListener("pointermove", handleMove)
    window.addEventListener("pointerup", handleUp)
    return () => {
      window.removeEventListener("pointermove", handleMove)
      window.removeEventListener("pointerup", handleUp)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [drag !== null, pxPerDay, localTasks])

  const startDrag = (event: React.PointerEvent, task: Task, mode: DragMode) => {
    if (event.button !== 0) return
    event.preventDefault()
    event.stopPropagation()
    dragRef.current = { taskId: task.id, mode, originX: event.clientX, deltaDays: 0 }
    setDrag(dragRef.current)
  }

  const handleSave = () => {
    if (!editingTask) return
    const original = localTasks.find((t) => t.id === editingTask.id)
    if (!original) return

    if (!editingTask.name.trim()) {
      toast.error("El nombre es requerido")
      return
    }

    const isMeeting = editingTask.type === "reunion"
    if (!isMeeting && editingTask.startDate && editingTask.deadline && new Date(editingTask.startDate) > new Date(editingTask.deadline)) {
      toast.error("La fecha de inicio no puede ser posterior al vencimiento")
      return
    }

    const assignee = users.find((user) => user.id === editingTask.assignedToId)
    persist(
      original,
      { ...editingTask, assignee: assignee?.name ?? "Sin asignar", updatedAt: new Date().toISOString() },
      {
        name: editingTask.name.trim(),
        status: editingTask.status,
        priority: editingTask.priority,
        description: editingTask.description ?? null,
        checklist: editingTask.checklist,
        assigned_to: editingTask.assignedToId ?? null,
        ...(isMeeting
          ? {}
          : { deadline: editingTask.deadline ?? null, start_date: editingTask.startDate ?? null }),
      },
      "Tarea actualizada",
    )
    setEditingTask(null)
  }

  const zoomButton = (level: ZoomLevel, label: string) => (
    <Button
      variant="outline"
      size="sm"
      onClick={() => setZoom(level)}
      className={zoom === level ? "bg-primary/10 border-primary/40" : ""}
    >
      {label}
    </Button>
  )

  return (
    <div className="space-y-4">
      {/* Controles */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          {zoomButton("day", "Día")}
          {zoomButton("week", "Semana")}
          {zoomButton("month", "Mes")}
          <Button variant="outline" size="sm" onClick={() => scrollToToday()}>
            <CalendarDays className="w-4 h-4 mr-2" />
            Hoy
          </Button>
        </div>

        <div className="flex items-center gap-4 text-xs text-muted-foreground">
          <span className="hidden md:inline">Arrastrá una barra para mover la tarea · tirá de sus bordes para cambiar inicio o vencimiento</span>
          <label className="flex items-center gap-2 cursor-pointer">
            <Switch checked={showClosed} onCheckedChange={setShowClosed} />
            Mostrar hechas/canceladas
          </label>
        </div>
      </div>

      {/* Gantt */}
      <div className="border border-border rounded-lg bg-card overflow-hidden">
        <div ref={scrollRef} className="overflow-x-auto">
          <div className="relative" style={{ width: NAME_COLUMN_WIDTH + timelineWidth }}>
            {/* Encabezado: meses (o años) y unidades */}
            <div className="sticky top-0 z-20 bg-muted/50 border-b border-border">
              <div className="flex border-b border-border/60">
                <div
                  className="sticky left-0 z-30 bg-muted flex-shrink-0 border-r border-border"
                  style={{ width: NAME_COLUMN_WIDTH }}
                />
                {groupHeaders.map((group) => (
                  <div
                    key={group.key}
                    className="px-2 py-1 text-xs font-semibold capitalize text-foreground border-r border-border/60 truncate flex-shrink-0"
                    style={{ width: group.width }}
                  >
                    {group.label}
                  </div>
                ))}
              </div>
              <div className="flex">
                <div
                  className="sticky left-0 z-30 bg-muted flex-shrink-0 border-r border-border px-3 py-2 text-sm font-semibold"
                  style={{ width: NAME_COLUMN_WIDTH }}
                >
                  Tarea
                </div>
                {unitHeaders.map((unit) => (
                  <div
                    key={unit.key}
                    className={`py-2 text-center text-[11px] border-r border-border/60 capitalize truncate flex-shrink-0 ${
                      unit.isToday ? "bg-primary/15 text-primary font-semibold" : "text-muted-foreground"
                    }`}
                    style={{ width: unit.width }}
                  >
                    {unit.label}
                  </div>
                ))}
              </div>
            </div>

            {/* Filas */}
            <div className="relative">
              {/* Fondo: fines de semana (zoom día) y la columna de hoy */}
              <div className="absolute inset-y-0 pointer-events-none" style={{ left: NAME_COLUMN_WIDTH, width: timelineWidth }}>
                {zoom === "day" &&
                  unitHeaders.map((unit, idx) =>
                    unit.isWeekend ? (
                      <div
                        key={unit.key}
                        className="absolute inset-y-0 bg-muted/40"
                        style={{ left: idx * pxPerDay, width: pxPerDay }}
                      />
                    ) : null,
                  )}
                <div className="absolute inset-y-0 bg-primary/10" style={{ left: todayOffset, width: Math.max(pxPerDay, 2) }} />
              </div>

              {scheduled.length === 0 && (
                <div className="p-8 text-sm text-muted-foreground" style={{ width: NAME_COLUMN_WIDTH + timelineWidth }}>
                  No hay tareas con fecha para mostrar.
                </div>
              )}

              {scheduled.map(({ task, range }) => {
                const isMeeting = task.type === "reunion"
                const isDragging = drag?.taskId === task.id
                const shown = isDragging ? applyDrag(range, isMeeting ? "move" : drag.mode, drag.deltaDays) : range
                const left = offsetFor(startOfDay(shown.start))
                const width = Math.max(
                  (differenceInCalendarDays(startOfDay(shown.end), startOfDay(shown.start)) + 1) * pxPerDay,
                  6,
                )
                const overdue = !isClosed(task) && range.end < new Date()
                const blocked = task.dependsOn.some((depId) => {
                  const dep = localTasks.find((t) => t.id === depId)
                  return dep && dep.status !== "hecho"
                })
                const checklistDone = task.checklist.filter((item) => item.done).length
                const progress = task.status === "hecho" ? 1 : task.checklist.length ? checklistDone / task.checklist.length : 0
                const dateLabel =
                  differenceInCalendarDays(shown.end, shown.start) === 0
                    ? format(shown.end, "d MMM", { locale: es })
                    : `${format(shown.start, "d MMM", { locale: es })} → ${format(shown.end, "d MMM", { locale: es })}`

                return (
                  <div key={task.id} className="flex border-b border-border/50 hover:bg-muted/20" style={{ height: ROW_HEIGHT }}>
                    <button
                      type="button"
                      onClick={() => setEditingTask(task)}
                      className="sticky left-0 z-10 bg-card flex-shrink-0 border-r border-border px-3 flex flex-col justify-center text-left hover:bg-muted/40"
                      style={{ width: NAME_COLUMN_WIDTH }}
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <span className={`text-sm truncate ${task.status === "hecho" ? "line-through text-muted-foreground" : "text-foreground"}`}>
                          {task.name}
                        </span>
                        {blocked && (
                          <Badge variant="outline" className="text-[10px] px-1 py-0 bg-yellow-500/10 text-yellow-500 border-yellow-500/30">
                            Bloqueada
                          </Badge>
                        )}
                      </div>
                      <span className="text-[11px] text-muted-foreground truncate">
                        {task.assignee} · {statusLabels[task.status]}
                      </span>
                    </button>

                    <div className="relative flex-shrink-0" style={{ width: timelineWidth }}>
                      <div
                        role="button"
                        tabIndex={0}
                        title={`${task.name}\n${dateLabel}${isMeeting ? " (reunión)" : ""}`}
                        onPointerDown={(event) => startDrag(event, task, "move")}
                        onKeyDown={(event) => event.key === "Enter" && setEditingTask(task)}
                        className={`group absolute top-2 h-8 rounded-md overflow-hidden select-none touch-none flex items-center shadow-sm ${
                          priorityBarColors[task.priority]
                        } ${isClosed(task) ? "opacity-50" : ""} ${overdue ? "ring-2 ring-red-500/70" : ""} ${
                          isDragging ? "cursor-grabbing opacity-90 shadow-lg" : "cursor-grab"
                        }`}
                        style={{ left, width }}
                      >
                        {progress > 0 && (
                          <div className="absolute inset-y-0 left-0 bg-black/20" style={{ width: `${progress * 100}%` }} />
                        )}
                        {!isMeeting && (
                          <span
                            onPointerDown={(event) => startDrag(event, task, "start")}
                            className="absolute inset-y-0 left-0 w-2 cursor-ew-resize opacity-0 group-hover:opacity-100 bg-white/40"
                          />
                        )}
                        <span className="relative px-2 text-xs text-white font-medium truncate pointer-events-none">
                          {width > 70 ? task.name : ""}
                        </span>
                        {!isMeeting && (
                          <span
                            onPointerDown={(event) => startDrag(event, task, "end")}
                            className="absolute inset-y-0 right-0 w-2 cursor-ew-resize opacity-0 group-hover:opacity-100 bg-white/40"
                          />
                        )}
                      </div>
                      {isDragging && (
                        <span
                          className="absolute top-0 text-[10px] font-medium text-foreground bg-background/90 px-1 rounded pointer-events-none whitespace-nowrap"
                          style={{ left: left + width + 6, top: 14 }}
                        >
                          {dateLabel}
                        </span>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      </div>

      {/* Tareas sin fecha: no se pueden ubicar en el tiempo hasta asignarles una */}
      {unscheduled.length > 0 && (
        <div className="border border-dashed border-border rounded-lg p-4">
          <div className="flex items-center gap-2 text-sm font-medium mb-3">
            <CalendarClock className="w-4 h-4 text-muted-foreground" />
            Sin fecha ({unscheduled.length})
            <span className="text-xs font-normal text-muted-foreground">— hacé click para planificarlas</span>
          </div>
          <div className="flex flex-wrap gap-2">
            {unscheduled.map((task) => (
              <Button key={task.id} variant="outline" size="sm" className="h-7 text-xs" onClick={() => setEditingTask(task)}>
                {task.name}
              </Button>
            ))}
          </div>
        </div>
      )}

      {/* Panel de edición */}
      <Sheet open={!!editingTask} onOpenChange={(open) => !open && setEditingTask(null)}>
        <SheetContent className="w-[400px] sm:w-[540px] gap-0">
          <SheetHeader className="border-b border-border">
            <SheetTitle>Editar tarea</SheetTitle>
          </SheetHeader>

          {editingTask && (
            <div className="flex-1 min-h-0 overflow-y-auto px-4 py-6 space-y-4">
              <div>
                <Label>Nombre</Label>
                <Input value={editingTask.name} onChange={(e) => setEditingTask({ ...editingTask, name: e.target.value })} />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Estado</Label>
                  <Select
                    value={editingTask.status}
                    onValueChange={(val) => setEditingTask({ ...editingTask, status: val as Task["status"] })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(Object.keys(statusLabels) as Task["status"][]).map((status) => (
                        <SelectItem key={status} value={status}>
                          {statusLabels[status]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div>
                  <Label>Prioridad</Label>
                  <Select
                    value={editingTask.priority}
                    onValueChange={(val) => setEditingTask({ ...editingTask, priority: val as Task["priority"] })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="baja">Baja</SelectItem>
                      <SelectItem value="media">Media</SelectItem>
                      <SelectItem value="alta">Alta</SelectItem>
                      <SelectItem value="critica">Crítica</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div>
                <Label>Responsable</Label>
                <Select
                  value={editingTask.assignedToId ? String(editingTask.assignedToId) : "none"}
                  onValueChange={(val) =>
                    setEditingTask({ ...editingTask, assignedToId: val === "none" ? null : Number(val) })
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Sin asignar</SelectItem>
                    {users.map((user) => (
                      <SelectItem key={user.id} value={String(user.id)}>
                        {user.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {editingTask.type === "reunion" ? (
                <p className="text-xs text-muted-foreground">
                  Reunión: {editingTask.startsAt && format(new Date(editingTask.startsAt), "d MMM yyyy HH:mm", { locale: es })}
                  {editingTask.endsAt && ` → ${format(new Date(editingTask.endsAt), "HH:mm")}`}. Podés moverla
                  de día arrastrando la barra; el horario exacto se edita desde la vista Lista.
                </p>
              ) : (
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>Inicio</Label>
                    <Input
                      type="date"
                      value={toDateInput(editingTask.startDate)}
                      onChange={(e) => {
                        const [y, m, d] = e.target.value.split("-").map(Number)
                        setEditingTask({
                          ...editingTask,
                          startDate: e.target.value ? new Date(y, m - 1, d).toISOString() : undefined,
                        })
                      }}
                    />
                  </div>
                  <div>
                    <Label>Vencimiento</Label>
                    <Input
                      type="datetime-local"
                      value={toDateTimeInput(editingTask.deadline)}
                      onChange={(e) =>
                        setEditingTask({
                          ...editingTask,
                          deadline: e.target.value ? new Date(e.target.value).toISOString() : undefined,
                        })
                      }
                    />
                  </div>
                </div>
              )}

              <div>
                <Label>Descripción</Label>
                <Textarea
                  value={editingTask.description || ""}
                  onChange={(e) => setEditingTask({ ...editingTask, description: e.target.value })}
                  rows={3}
                />
              </div>

              {editingTask.checklist.length > 0 && (
                <div>
                  <Label>Checklist</Label>
                  <div className="space-y-2 mt-2">
                    {editingTask.checklist.map((item) => (
                      <div key={item.id} className="flex items-center gap-2">
                        <Checkbox
                          checked={item.done}
                          onCheckedChange={() =>
                            setEditingTask({
                              ...editingTask,
                              checklist: editingTask.checklist.map((c) => (c.id === item.id ? { ...c, done: !c.done } : c)),
                            })
                          }
                        />
                        <span className={`text-sm ${item.done ? "line-through text-muted-foreground" : "text-foreground"}`}>
                          {item.text}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          <div className="flex gap-2 p-4 border-t border-border">
            <Button onClick={handleSave} className="flex-1">
              Guardar cambios
            </Button>
            <Button variant="outline" onClick={() => setEditingTask(null)} className="flex-1">
              Cancelar
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
