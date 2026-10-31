"use client"

import { useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { ChevronLeft, ChevronRight, CalendarIcon, CalendarOff } from "lucide-react"
import type { Task } from "@/lib/types/task"
import { CalendarSyncBadge } from "./CalendarSyncBadge"
import { useTranslation } from "@/hooks/useTranslation"
import { AiBookingApprovalBadge } from "./AiBookingApprovalBadge"
import { GoogleCalendarEventDialog } from "@/components/calendar/GoogleCalendarEventDialog"
import { CalendarBlockApiError } from "@/lib/api/calendar-availability-blocks"
import { listGoogleCalendarEvents, type GoogleCalendarEvent } from "@/lib/api/google-calendar-events"
import { useAuthStore } from "@/store/useAuthStore"
import {
  format,
  startOfMonth,
  endOfMonth,
  startOfWeek,
  endOfWeek,
  eachDayOfInterval,
  isSameMonth,
  isSameDay,
  addMonths,
  subMonths,
  addWeeks,
  subWeeks,
  addDays,
  subDays,
  startOfDay,
} from "date-fns"
import { es } from "date-fns/locale"

type CalendarView = "month" | "week" | "day"
type GoogleStatus = "loading" | "ready" | "not_connected" | "error"

// RFC 3339 with a numeric offset; the API rejects the "Z" shorthand formatISO emits in UTC.
const RFC3339 = "yyyy-MM-dd'T'HH:mm:ssxxx"

const priorityColors = {
  baja: "bg-gray-500/80",
  media: "bg-blue-500/80",
  alta: "bg-orange-500/80",
  critica: "bg-red-500/80",
}

export function TaskCalendarView({
  tasks,
  onBlockAgenda,
  googleRefreshKey,
}: {
  tasks: Task[]
  onBlockAgenda?: (date: string) => void
  googleRefreshKey?: number
}) {
  const { t } = useTranslation()
  const tr = (key: string, params?: Record<string, string | number>) => t(`calendarEvents.${key}`, params)
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId)
  const [view, setView] = useState<CalendarView>("month")
  const [currentDate, setCurrentDate] = useState(new Date())
  // Status is derived from the request key so no state is set synchronously in the effect.
  const [googleLoad, setGoogleLoad] = useState<{
    key: string
    workspaceId: typeof activeWorkspaceId
    status: Exclude<GoogleStatus, "loading">
    events: GoogleCalendarEvent[]
  } | null>(null)
  const [selected, setSelected] = useState<{ event: GoogleCalendarEvent; workspaceId: typeof activeWorkspaceId } | null>(null)
  const [retryKey, setRetryKey] = useState(0)
  const loadGeneration = useRef(0)
  // Events and the open detail of another workspace are never shown, even while the new one loads.
  const googleEvents = googleLoad?.workspaceId === activeWorkspaceId ? googleLoad.events : []
  const selectedEvent = selected?.workspaceId === activeWorkspaceId ? selected.event : null
  const setSelectedEvent = (event: GoogleCalendarEvent | null) => setSelected(event && { event, workspaceId: activeWorkspaceId })

  const handlePrevious = () => {
    if (view === "month") setCurrentDate(subMonths(currentDate, 1))
    else if (view === "week") setCurrentDate(subWeeks(currentDate, 1))
    else setCurrentDate(subDays(currentDate, 1))
  }

  const handleNext = () => {
    if (view === "month") setCurrentDate(addMonths(currentDate, 1))
    else if (view === "week") setCurrentDate(addWeeks(currentDate, 1))
    else setCurrentDate(addDays(currentDate, 1))
  }

  const handleToday = () => {
    setCurrentDate(new Date())
  }

  const getDaysInView = () => {
    if (view === "month") {
      const start = startOfWeek(startOfMonth(currentDate))
      const end = endOfWeek(endOfMonth(currentDate))
      return eachDayOfInterval({ start, end })
    } else if (view === "week") {
      const start = startOfWeek(currentDate)
      const end = endOfWeek(currentDate)
      return eachDayOfInterval({ start, end })
    } else {
      return [currentDate]
    }
  }

  const getTasksForDay = (date: Date) => {
    return tasks.filter((task) => task.deadline && isSameDay(new Date(task.deadline), date))
  }

  const getGoogleEventsForDay = (day: Date) => {
    const dayStart = startOfDay(day)
    const nextDay = addDays(dayStart, 1)
    const date = format(day, "yyyy-MM-dd")
    return googleEvents.filter((event) =>
      event.all_day
        ? event.start <= date && date < event.end
        : new Date(event.start) < nextDay && new Date(event.end) > dayStart,
    )
  }

  const days = getDaysInView()
  const tasksWithDeadlines = tasks.filter((t) => t.deadline)
  const timeMin = format(startOfDay(days[0]), RFC3339)
  const timeMax = format(addDays(startOfDay(days[days.length - 1]), 1), RFC3339)
  const requestKey = `${timeMin}|${timeMax}|${googleRefreshKey ?? 0}|${activeWorkspaceId}|${retryKey}`
  const googleStatus: GoogleStatus = googleLoad?.key === requestKey ? googleLoad.status : "loading"

  useEffect(() => {
    const generation = ++loadGeneration.current
    const settle = (status: Exclude<GoogleStatus, "loading">, events: GoogleCalendarEvent[]) => {
      if (generation === loadGeneration.current) setGoogleLoad({ key: requestKey, workspaceId: activeWorkspaceId, status, events })
    }
    listGoogleCalendarEvents({ time_min: timeMin, time_max: timeMax })
      .then((result) => settle("ready", result.events))
      .catch((error: unknown) => {
        settle(error instanceof CalendarBlockApiError && error.code === "calendar_not_connected" ? "not_connected" : "error", [])
      })
  }, [requestKey, timeMin, timeMax, activeWorkspaceId])

  const renderGoogleEvent = (event: GoogleCalendarEvent, size: "month" | "week" | "day") => {
    const label = `${event.all_day ? tr("allDay") : format(new Date(event.start), "HH:mm")} · ${event.summary ?? tr("untitled")}`
    const Icon = event.kind === "availability_block" ? CalendarOff : CalendarIcon
    const box = size === "day" ? "rounded-lg p-4 text-sm" : `rounded text-xs ${size === "week" ? "p-2" : "p-1"}`
    return (
      <button
        key={event.id}
        type="button"
        onClick={() => setSelectedEvent(event)}
        title={label}
        className={`flex w-full items-center gap-1 truncate border border-sky-500/40 bg-sky-500/10 text-left text-foreground hover:bg-sky-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${box}`}
      >
        <Icon className="size-3 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden="true" />
        <span className="truncate">{label}</span>
      </button>
    )
  }

  return (
    <div className="space-y-4">
      {/* Controls */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 flex-wrap items-center gap-3">
          <div className="flex items-center gap-2">
            <Button variant="outline" size="icon" className="h-11 w-11" onClick={handlePrevious}>
              <ChevronLeft className="w-4 h-4" />
            </Button>
            <Button variant="outline" size="sm" className="min-h-11" onClick={handleToday}>
              Hoy
            </Button>
            <Button variant="outline" size="icon" className="h-11 w-11" onClick={handleNext}>
              <ChevronRight className="w-4 h-4" />
            </Button>
          </div>

          <h2 className="text-lg font-semibold text-foreground">
            {view === "month" && format(currentDate, "MMMM yyyy", { locale: es })}
            {view === "week" && `Semana del ${format(startOfWeek(currentDate), "dd MMM", { locale: es })}`}
            {view === "day" && format(currentDate, "dd MMMM yyyy", { locale: es })}
          </h2>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          {onBlockAgenda && (
            <Button
              type="button"
              variant="outline"
              className="min-h-11 gap-2"
              onClick={() => onBlockAgenda(format(currentDate, "yyyy-MM-dd"))}
              title={t("calendarAvailability.entry")}
              aria-label={t("calendarAvailability.entry")}
            >
              <CalendarOff className="h-4 w-4" aria-hidden="true" />
              {t("calendarAvailability.entry")}
            </Button>
          )}
          <Tabs value={view} onValueChange={(val) => setView(val as CalendarView)}>
            <TabsList className="h-auto">
              <TabsTrigger value="month" className="min-h-11">Mes</TabsTrigger>
              <TabsTrigger value="week" className="min-h-11">Semana</TabsTrigger>
              <TabsTrigger value="day" className="min-h-11">Día</TabsTrigger>
            </TabsList>
          </Tabs>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3 text-sm">
        <span className="inline-flex items-center gap-1.5 rounded border border-sky-500/40 bg-sky-500/10 px-2 py-0.5 text-xs text-foreground">
          <CalendarIcon className="size-3 text-sky-600 dark:text-sky-400" aria-hidden="true" />
          {tr("legend")}
        </span>
        {googleStatus === "loading" && <span role="status" className="text-muted-foreground">{tr("loading")}</span>}
        {googleStatus === "not_connected" && <span className="text-muted-foreground">{tr("notConnected")}</span>}
        {googleStatus === "error" && (
          <>
            <span role="alert" className="text-destructive">{tr("loadError")}</span>
            <Button type="button" variant="outline" className="min-h-11" onClick={() => setRetryKey((k) => k + 1)}>{tr("retry")}</Button>
          </>
        )}
      </div>

      {/* Calendar Grid */}
      <div className="border border-border rounded-lg bg-card overflow-hidden">
        {view === "month" && (
          <div>
            {/* Weekday headers */}
            <div className="grid grid-cols-7 border-b border-border bg-muted/50">
              {["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"].map((day) => (
                <div key={day} className="p-2 text-center text-sm font-medium text-foreground">
                  {day}
                </div>
              ))}
            </div>

            {/* Days grid */}
            <div className="grid grid-cols-7">
              {days.map((day, idx) => {
                const dayTasks = getTasksForDay(day)
                const dayEvents = getGoogleEventsForDay(day)
                const isToday = isSameDay(day, new Date())
                const isCurrentMonth = isSameMonth(day, currentDate)

                return (
                  <div
                    key={idx}
                    className={`min-h-[120px] p-2 border-r border-b border-border ${!isCurrentMonth ? "bg-muted/20" : ""} ${isToday ? "bg-primary/5" : ""}`}
                  >
                    <div className="flex items-center justify-between mb-1">
                      <span
                        className={`text-sm font-medium ${isToday ? "text-primary" : isCurrentMonth ? "text-foreground" : "text-muted-foreground"}`}
                      >
                        {format(day, "d")}
                      </span>
                      {isToday && (
                        <Badge variant="default" className="text-xs h-5">
                          Hoy
                        </Badge>
                      )}
                    </div>

                    <div className="space-y-1">
                      {dayTasks.slice(0, 3).map((task) => (
                        <div
                          key={task.id}
                          className={`text-xs p-1 rounded ${priorityColors[task.priority]} text-white truncate`}
                          title={`${format(new Date(task.deadline!), "HH:mm")} hs · ${task.name}`}
                        >
                          <span className="mr-1 font-semibold tabular-nums">{format(new Date(task.deadline!), "HH:mm")}</span>
                          {task.name}
                        </div>
                      ))}
                      {dayTasks.length > 3 && (
                        <div className="text-xs text-muted-foreground">+{dayTasks.length - 3} más</div>
                      )}
                      {dayEvents.slice(0, 2).map((event) => renderGoogleEvent(event, "month"))}
                      {dayEvents.length > 2 && (
                        <button
                          type="button"
                          className="text-xs text-sky-700 underline-offset-2 hover:underline dark:text-sky-300"
                          onClick={() => { setView("day"); setCurrentDate(day) }}
                        >
                          {tr("more", { count: dayEvents.length - 2 })}
                        </button>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        )}

        {view === "week" && (
          <div>
            {/* Week view */}
            <div className="grid grid-cols-7">
              {days.map((day, idx) => {
                const dayTasks = getTasksForDay(day)
                const isToday = isSameDay(day, new Date())

                return (
                  <div key={idx} className="border-r border-b border-border">
                    <div
                      className={`p-3 border-b border-border text-center ${isToday ? "bg-primary/10" : "bg-muted/30"}`}
                    >
                      <div className={`text-xs font-medium ${isToday ? "text-primary" : "text-muted-foreground"}`}>
                        {format(day, "EEE", { locale: es })}
                      </div>
                      <div className={`text-2xl font-bold ${isToday ? "text-primary" : "text-foreground"}`}>
                        {format(day, "d")}
                      </div>
                    </div>

                    <div className="p-2 space-y-2 min-h-[400px]">
                      {dayTasks.map((task) => (
                        <div
                          key={task.id}
                          className={`p-2 rounded ${priorityColors[task.priority]} text-white`}
                        >
                          <div className="mb-1 text-xs font-medium">
                            <span className="mr-1 tabular-nums">{format(new Date(task.deadline!), "HH:mm")} hs</span>
                            {task.name}
                          </div>
                          <div className="text-xs opacity-80">{task.assignee}</div>
                        </div>
                      ))}
                      {getGoogleEventsForDay(day).map((event) => renderGoogleEvent(event, "week"))}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        )}

        {view === "day" && (
          <div className="p-4">
            <div className="space-y-3">
              {getTasksForDay(currentDate).map((task) => (
                <div key={task.id} className={`p-4 rounded-lg ${priorityColors[task.priority]} text-white`}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex-1">
                      <h4 className="font-semibold mb-1">{task.name}</h4>
                      <p className="text-sm font-medium tabular-nums opacity-90">
                        {format(new Date(task.deadline!), "HH:mm")} hs
                      </p>
                      <p className="text-sm opacity-90">Responsable: {task.assignee}</p>
                      <p className="text-sm opacity-90">Tipo: {task.type}</p>
                      {task.description && <p className="text-sm mt-2 opacity-80">{task.description}</p>}
                    </div>
                    {task.type === "reunion" && (
                      <div className="shrink-0 space-y-1.5 rounded-md bg-background/90 p-1.5">
                        <CalendarSyncBadge taskId={task.id} sync={task.calendarSync} />
                        <AiBookingApprovalBadge task={task} />
                      </div>
                    )}
                  </div>
                </div>
              ))}

              {getGoogleEventsForDay(currentDate).map((event) => renderGoogleEvent(event, "day"))}

              {getTasksForDay(currentDate).length === 0 && getGoogleEventsForDay(currentDate).length === 0 && (
                <div className="text-center py-12 text-muted-foreground">
                  <CalendarIcon className="w-12 h-12 mx-auto mb-3 opacity-30" />
                  <p>No hay tareas para este día</p>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      <GoogleCalendarEventDialog
        event={selectedEvent}
        onOpenChange={(open) => { if (!open) setSelectedEvent(null) }}
        onDeleted={(id) => setGoogleLoad((prev) => prev && { ...prev, events: prev.events.filter((e) => e.id !== id) })}
      />
    </div>
  )
}
