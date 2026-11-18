"use client"

import { useState } from "react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { CalendarClock, ExternalLink, Loader2, RefreshCw } from "lucide-react"
import { useToast } from "@/components/Toast"
import type { Task, TaskCalendarSyncInfo } from "@/lib/types/task"
import { retryTaskCalendarSync } from "@/lib/api/google-calendar"

const STATUS_STYLES: Record<TaskCalendarSyncInfo["status"], string> = {
  synced: "bg-success/10 text-success border-transparent",
  pending: "bg-muted text-muted-foreground border-transparent",
  error: "bg-destructive/10 text-destructive border-transparent",
  paused: "bg-muted text-muted-foreground border-transparent",
}

const STATUS_LABELS: Record<TaskCalendarSyncInfo["status"], string> = {
  synced: "Sincronizada",
  pending: "Pendiente de sincronizar",
  error: "Error al sincronizar",
  paused: "Sincronización pausada",
}

interface CalendarSyncBadgeProps {
  taskId: string
  sync?: TaskCalendarSyncInfo | null
  onRetried?: () => void
  /** Falso donde el Meet ya tiene su propio botón (la tarjeta del calendario). */
  showMeetLink?: boolean
}

/**
 * Las reuniones siempre muestran su estado en Google Calendar. El resto de los
 * tipos son recordatorios opcionales: el back crea su fila de sync recién
 * cuando el responsable tiene Google conectado, así que sin fila no hay badge.
 */
export function showsCalendarSync(task: Pick<Task, "type" | "calendarSync">): boolean {
  return task.type === "reunion" || Boolean(task.calendarSync)
}

export function CalendarSyncBadge({ taskId, sync, onRetried, showMeetLink = true }: CalendarSyncBadgeProps) {
  const { addToast } = useToast()
  const [retrying, setRetrying] = useState(false)
  const effectiveSync = sync ?? {
    status: "pending" as const,
    htmlLink: null,
    meetLink: null,
    lastError: "not_connected",
    syncedAt: null,
  }

  const statusHint = effectiveSync.lastError === "not_connected"
    ? "El responsable todavía no conectó Google Calendar"
    : effectiveSync.lastError === "needs_reauth"
      ? "El responsable debe volver a autorizar Google Calendar"
      : effectiveSync.lastError === "conference_pending"
        ? "Google Meet todavía se está generando"
        : effectiveSync.lastError ?? undefined

  const handleRetry = async (e: React.MouseEvent) => {
    e.stopPropagation()
    setRetrying(true)
    const result = await retryTaskCalendarSync(taskId)
    setRetrying(false)

    if (result.error) {
      addToast({ type: "error", title: result.error })
      return
    }

    addToast({ type: "success", title: "Reintento encolado" })
    onRetried?.()
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge
        variant="outline"
        className={`gap-1 text-xs font-medium ${STATUS_STYLES[effectiveSync.status]}`}
        title={statusHint}
      >
        <CalendarClock className="size-3" />
        {STATUS_LABELS[effectiveSync.status]}
      </Badge>

      {showMeetLink && effectiveSync.meetLink && (
        <a
          href={effectiveSync.meetLink}
          target="_blank"
          rel="noreferrer"
          onClick={(e) => e.stopPropagation()}
          className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
        >
          <ExternalLink className="size-3" />
          Unirse con Google Meet
        </a>
      )}

      {effectiveSync.status === "error" && (
        <Button
          size="sm"
          variant="ghost"
          className="h-6 gap-1 px-1.5 text-xs"
          onClick={handleRetry}
          disabled={retrying}
        >
          {retrying ? <Loader2 className="size-3 animate-spin" /> : <RefreshCw className="size-3" />}
          Reintentar
        </Button>
      )}
    </div>
  )
}
