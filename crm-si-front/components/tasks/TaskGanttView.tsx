"use client"

import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { ZoomIn, ZoomOut, Calendar } from "lucide-react"
import type { Task } from "@/lib/types/task"
import {
  format,
  addDays,
  startOfWeek,
  endOfWeek,
  eachDayOfInterval,
  differenceInDays,
  startOfMonth,
  endOfMonth,
} from "date-fns"
import { es } from "date-fns/locale"
import { TaskDetailSheet } from "./TaskDetailSheet"

type ZoomLevel = "day" | "week" | "month"

// Clases literales (Tailwind no ve las armadas en runtime) y tinte + texto del
// mismo tono, legible en ambos temas.
const priorityColors = {
  baja: "border border-gray-500/40 bg-gray-500/15 text-gray-800 dark:bg-gray-500/25 dark:text-gray-200",
  media: "border border-blue-500/40 bg-blue-500/15 text-blue-800 dark:bg-blue-500/25 dark:text-blue-200",
  alta: "border border-orange-500/40 bg-orange-500/15 text-orange-800 dark:bg-orange-500/25 dark:text-orange-200",
  critica: "border border-red-500/40 bg-red-500/15 text-red-800 dark:bg-red-500/25 dark:text-red-200",
}

export function TaskGanttView({ tasks }: { tasks: Task[] }) {
  const [zoom, setZoom] = useState<ZoomLevel>("week")
  const [localTasks, setLocalTasks] = useState(tasks)
  const [selectedTask, setSelectedTask] = useState<Task | null>(null)

  useEffect(() => {
    setLocalTasks(tasks)
    setSelectedTask(null)
  }, [tasks])

  // Calculate date range
  const today = new Date()
  const startDate = startOfWeek(addDays(today, -7))
  const endDate = endOfWeek(addDays(today, 30))

  const getDaysInRange = () => {
    if (zoom === "day") {
      return eachDayOfInterval({ start: startDate, end: endDate })
    } else if (zoom === "week") {
      const weeks = []
      let current = startDate
      while (current <= endDate) {
        weeks.push(current)
        current = addDays(current, 7)
      }
      return weeks
    } else {
      const months = []
      let current = startOfMonth(startDate)
      while (current <= endOfMonth(endDate)) {
        months.push(current)
        current = addDays(current, 30)
      }
      return months
    }
  }

  const dateColumns = getDaysInRange()
  const columnWidth = zoom === "day" ? 60 : zoom === "week" ? 100 : 120

  const getTaskPosition = (task: Task) => {
    if (!task.deadline) return null

    const taskDate = new Date(task.deadline)
    const daysDiff = differenceInDays(taskDate, startDate)
    const left = daysDiff * (columnWidth / (zoom === "day" ? 1 : zoom === "week" ? 7 : 30))

    return {
      left: Math.max(0, left),
      width: columnWidth,
    }
  }

  const tasksWithDeadlines = localTasks.filter((t) => t.deadline && t.status !== "hecho" && t.status !== "cancelado")

  return (
    <div className="space-y-4">
      {/* En teléfonos los botones de escala van sin ícono para que la barra entre en 390px. */}
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2 max-sm:gap-1.5" role="group" aria-label="Escala del Gantt">
          <Button
            variant="outline"
            size="sm"
            onClick={() => setZoom("day")}
            className={zoom === "day" ? "bg-primary/10" : ""}
            aria-pressed={zoom === "day"}
          >
            <Calendar className="w-4 h-4 mr-2 max-sm:hidden" aria-hidden="true" />
            Día
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => setZoom("week")}
            className={zoom === "week" ? "bg-primary/10" : ""}
            aria-pressed={zoom === "week"}
          >
            <Calendar className="w-4 h-4 mr-2 max-sm:hidden" aria-hidden="true" />
            Semana
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => setZoom("month")}
            className={zoom === "month" ? "bg-primary/10" : ""}
            aria-pressed={zoom === "month"}
          >
            <Calendar className="w-4 h-4 mr-2 max-sm:hidden" aria-hidden="true" />
            Mes
          </Button>
        </div>

        <div className="flex items-center gap-2 max-sm:gap-1.5">
          <Button variant="outline" size="icon" onClick={() => setZoom("day")} aria-label="Acercar (vista por día)" title="Acercar">
            <ZoomIn className="w-4 h-4" aria-hidden="true" />
          </Button>
          <Button variant="outline" size="icon" onClick={() => setZoom("month")} aria-label="Alejar (vista por mes)" title="Alejar">
            <ZoomOut className="w-4 h-4" aria-hidden="true" />
          </Button>
        </div>
      </div>

      {/* Gantt Chart */}
      <div className="border border-border rounded-lg bg-card overflow-hidden">
        <div className="overflow-x-auto">
          {/* relative: la línea de "Hoy" se posiciona y desplaza con el gráfico, no con la página. */}
          <div className="relative min-w-max">
            {/* Header */}
            <div className="flex border-b border-border bg-muted/50 sticky top-0 z-10">
              <div className="w-64 p-3 font-semibold text-sm border-r border-border flex-shrink-0">Tarea</div>
              <div className="flex">
                {dateColumns.map((date, idx) => (
                  <div
                    key={idx}
                    className="p-3 text-center border-r border-border text-xs font-medium"
                    style={{ minWidth: columnWidth }}
                  >
                    {zoom === "day" && format(date, "EEE dd", { locale: es })}
                    {zoom === "week" && format(date, "dd MMM", { locale: es })}
                    {zoom === "month" && format(date, "MMM yyyy", { locale: es })}
                  </div>
                ))}
              </div>
            </div>

            {/* Tasks */}
            <div className="relative">
              {tasksWithDeadlines.length === 0 && (
                <p className="sticky left-0 w-fit px-4 py-10 text-sm text-muted-foreground">
                  No hay tareas pendientes con fecha para mostrar en el Gantt.
                </p>
              )}
              {tasksWithDeadlines.map((task, taskIdx) => {
                const position = getTaskPosition(task)
                if (!position) return null

                const hasDependencies = task.dependsOn.length > 0
                const hasUnfinishedDeps = task.dependsOn.some((depId) => {
                  const depTask = localTasks.find((t) => t.id === depId)
                  return depTask && depTask.status !== "hecho"
                })

                return (
                  <div key={task.id} className="flex border-b border-border/50 hover:bg-muted/20 relative">
                    {/* Task Name */}
                    <div className="w-64 p-3 flex items-center gap-2 border-r border-border flex-shrink-0">
                      <span className="text-sm text-foreground truncate">{task.name}</span>
                      {hasDependencies && hasUnfinishedDeps && (
                        <Badge
                          variant="outline"
                          className="text-xs bg-warning/10 text-warning border-warning/30"
                        >
                          Bloqueado
                        </Badge>
                      )}
                    </div>

                    {/* Timeline */}
                    <div className="relative flex-1" style={{ height: 48 }}>
                      <button
                        type="button"
                        className={`absolute top-2 h-8 rounded cursor-pointer transition-opacity hover:opacity-80 flex items-center px-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${priorityColors[task.priority]}`}
                        style={{
                          left: position.left,
                          width: position.width,
                        }}
                        onClick={() => setSelectedTask(task)}
                        aria-label={`Ver detalles de ${task.name} (${task.assignee})`}
                      >
                        <span className="text-xs font-medium truncate">{task.assignee}</span>
                      </button>

                      {/* Dependency indicators */}
                      {hasDependencies && (
                        <div
                          className="absolute left-2 top-4 w-1 h-1 rounded-full bg-warning"
                          title="Tiene dependencias"
                        />
                      )}
                    </div>
                  </div>
                )
              })}
            </div>

            {/* Today marker */}
            <div
              className="absolute top-0 bottom-0 w-0.5 bg-destructive pointer-events-none z-20"
              style={{
                left:
                  264 +
                  differenceInDays(today, startDate) * (columnWidth / (zoom === "day" ? 1 : zoom === "week" ? 7 : 30)),
              }}
            >
              <div className="absolute top-1 left-1 text-xs text-destructive font-semibold whitespace-nowrap">Hoy</div>
            </div>
          </div>
        </div>
      </div>

      <TaskDetailSheet
        task={selectedTask}
        onOpenChange={(open) => !open && setSelectedTask(null)}
        onSaved={(taskId, changes) =>
          setLocalTasks((prev) =>
            prev.map((t) => (t.id === taskId ? { ...t, ...changes, updatedAt: new Date().toISOString() } : t)),
          )
        }
      />
    </div>
  )
}
