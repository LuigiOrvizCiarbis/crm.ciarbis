"use client"

import { useId, useRef, useState } from "react"
import { CalendarIcon, CalendarOff, ExternalLink } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { CalendarBlockApiError, deleteCalendarAvailabilityBlock } from "@/lib/api/calendar-availability-blocks"
import { deleteGoogleCalendarEvent, type GoogleCalendarEvent } from "@/lib/api/google-calendar-events"

// Calendar-date arithmetic only. UTC prevents the browser's timezone/DST from changing a date.
function addCalendarDays(value: string, days: number): string {
  const [year, month, day] = value.split("-").map(Number)
  const date = new Date(Date.UTC(year, month - 1, day + days))
  return date.toISOString().slice(0, 10)
}

export interface GoogleCalendarEventDialogProps {
  event: GoogleCalendarEvent | null
  onOpenChange: (open: boolean) => void
  onDeleted: (eventId: string) => void
}

export function GoogleCalendarEventDialog({ event, onOpenChange, onDeleted }: GoogleCalendarEventDialogProps) {
  const { t, language } = useTranslation()
  const { addToast } = useToast()
  const tr = (key: string, params?: Record<string, string | number>) => t(`calendarEvents.${key}`, params)
  const id = useId()
  const busyRef = useRef(false)
  const [confirming, setConfirming] = useState(false)
  const [notifyGuests, setNotifyGuests] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState("")
  const [displayedEvent, setDisplayedEvent] = useState(event)
  // Keep the last event mounted through Radix's close animation; replace it when another opens.
  if (event && event.id !== displayedEvent?.id) {
    setDisplayedEvent(event)
    setConfirming(false)
    setNotifyGuests(true)
    setError("")
  }

  function close() {
    if (!busyRef.current) onOpenChange(false)
  }

  function errorMessage(err: unknown): string {
    if (err instanceof CalendarBlockApiError) {
      if (err.code === "calendar_not_connected") return tr("errors.notConnected")
      if (err.code === "calendar_busy") return tr("errors.busy")
      if (err.code === "event_not_deletable") return tr("errors.notDeletable")
      if (err.status === 401) return tr("errors.session")
      if (err.status === 403) return tr("errors.workspace")
    }
    return tr("errors.delete")
  }

  async function remove(target: GoogleCalendarEvent) {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true)
    setError("")
    const isBlock = target.kind === "availability_block"
    try {
      if (isBlock) await deleteCalendarAvailabilityBlock(target.id)
      else await deleteGoogleCalendarEvent(target.id, target.has_guests && notifyGuests)
      addToast({ type: "success", title: tr(isBlock ? "unblocked" : "deleted") })
      busyRef.current = false
      setBusy(false)
      onDeleted(target.id)
      onOpenChange(false)
    } catch (err) {
      busyRef.current = false
      setBusy(false)
      setError(errorMessage(err))
    }
  }

  function timeLabel(target: GoogleCalendarEvent): string {
    if (target.all_day) {
      const lastDay = addCalendarDays(target.end, -1)
      return `${target.start}${lastDay === target.start ? "" : ` → ${lastDay}`} · ${tr("allDay")}`
    }
    const formatter = new Intl.DateTimeFormat(language === "en" ? "en-US" : "es-AR", { dateStyle: "medium", timeStyle: "short" })
    return `${formatter.format(new Date(target.start))} → ${formatter.format(new Date(target.end))}`
  }

  const isBlock = displayedEvent?.kind === "availability_block"
  const Icon = isBlock ? CalendarOff : CalendarIcon

  return (
    <Dialog open={event !== null} onOpenChange={(next) => { if (!next) close() }}>
      <DialogContent className="max-h-[90dvh] w-[calc(100%_-_1rem)] overflow-y-auto rounded-lg p-4 sm:max-w-md sm:p-6 [&>button]:min-h-11 [&>button]:min-w-11" onEscapeKeyDown={(e) => { if (busyRef.current) e.preventDefault() }} onInteractOutside={(e) => { if (busyRef.current) e.preventDefault() }}>
        {displayedEvent && (
          <>
            <DialogHeader className="pr-10 text-left">
              <DialogTitle className="flex items-start gap-2 break-words"><Icon aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-400" />{displayedEvent.summary ?? tr("untitled")}</DialogTitle>
              <DialogDescription className="space-y-1">
                {isBlock && <span className="block">{tr("availabilityBlock")}</span>}
                <span className="block">{timeLabel(displayedEvent)}</span>
              </DialogDescription>
            </DialogHeader>

            {displayedEvent.recurring && <p className="rounded-md bg-muted/50 px-3 py-2 text-sm text-muted-foreground">{tr("recurringNote")}</p>}
            {displayedEvent.html_link && <a href={displayedEvent.html_link} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center gap-1 self-start rounded-md px-2 text-sm text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">{tr("viewGoogle")}<ExternalLink aria-hidden="true" className="size-3.5" /></a>}

            {displayedEvent.can_delete && (displayedEvent.kind === "event" || isBlock) ? (
              confirming ? (
                <div className="space-y-3 rounded-md bg-muted/50 p-3">
                  <p className="text-sm">{tr(isBlock ? "confirmUnblock" : "confirmDelete")}</p>
                  {!isBlock && displayedEvent.has_guests && (
                    <div className="flex min-h-11 items-center gap-2">
                      <Checkbox id={`${id}-notify`} checked={notifyGuests} disabled={busy} onCheckedChange={(checked) => setNotifyGuests(checked === true)} />
                      <label htmlFor={`${id}-notify`} className="text-sm">{tr("notifyGuests")}</label>
                    </div>
                  )}
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => { setConfirming(false); setError("") }}>{tr("cancel")}</Button>
                    <Button type="button" variant="destructive" className="min-h-11" disabled={busy} aria-busy={busy} onClick={() => void remove(displayedEvent)}>{busy ? tr("deleting") : isBlock ? tr("unblock") : tr("confirm")}</Button>
                  </div>
                </div>
              ) : (
                <div className="flex justify-end">
                  <Button type="button" variant="outline" className="min-h-11 w-full sm:w-auto" onClick={() => setConfirming(true)}>{tr(isBlock ? "unblock" : "delete")}</Button>
                </div>
              )
            ) : (
              <p className="text-sm text-muted-foreground">{tr(displayedEvent.kind === "event" ? "notDeletable" : "managedElsewhere")}</p>
            )}

            {error && <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
            <div className="flex justify-end"><Button type="button" variant="ghost" className="min-h-11" disabled={busy} onClick={close}>{tr("close")}</Button></div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
