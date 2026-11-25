"use client"

import { useId, useRef, useState } from "react"
import { ExternalLink, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { CalendarBlockApiError, deleteCalendarAvailabilityBlock } from "@/lib/api/calendar-availability-blocks"
import { deleteGoogleCalendarEvent, type GoogleCalendarEvent } from "@/lib/api/google-calendar-events"
import { CalendarEventAction, CalendarEventPopover, formatEventDay, formatEventWhen } from "./CalendarEventPopover"

// Calendar-date arithmetic only. UTC prevents the browser's timezone/DST from changing a date.
function addCalendarDays(value: string, days: number): string {
  const [year, month, day] = value.split("-").map(Number)
  const date = new Date(Date.UTC(year, month - 1, day + days))
  return date.toISOString().slice(0, 10)
}

/** `yyyy-MM-dd` como fecha local, para formatearla sin correr el día por la zona horaria. */
function localDate(value: string): Date {
  const [year, month, day] = value.split("-").map(Number)
  return new Date(year, month - 1, day)
}

export interface GoogleCalendarEventPopoverProps {
  event: GoogleCalendarEvent
  anchor: HTMLElement
  open: boolean
  onOpenChange: (open: boolean) => void
  onDeleted: (eventId: string) => void
}

export function GoogleCalendarEventPopover({ event, anchor, open, onOpenChange, onDeleted }: GoogleCalendarEventPopoverProps) {
  const { t, language } = useTranslation()
  const { addToast } = useToast()
  const tr = (key: string, params?: Record<string, string | number>) => t(`calendarEvents.${key}`, params)
  const id = useId()
  const busyRef = useRef(false)
  const [confirming, setConfirming] = useState(false)
  const [notifyGuests, setNotifyGuests] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState("")
  const [shownFor, setShownFor] = useState<string | null>(null)
  // Each opening starts clean; the content stays mounted through the close animation.
  if (open && shownFor !== event.id) {
    setShownFor(event.id)
    setConfirming(false)
    setNotifyGuests(true)
    setError("")
  } else if (!open && shownFor !== null) {
    setShownFor(null)
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

  const isBlock = event.kind === "availability_block"
  const deletable = event.can_delete && (event.kind === "event" || isBlock)

  async function remove() {
    if (busyRef.current) return
    busyRef.current = true
    setBusy(true)
    setError("")
    try {
      if (isBlock) await deleteCalendarAvailabilityBlock(event.id)
      else await deleteGoogleCalendarEvent(event.id, event.has_guests && notifyGuests)
      addToast({ type: "success", title: tr(isBlock ? "unblocked" : "deleted") })
      onDeleted(event.id)
      onOpenChange(false)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }

  function whenLabel(): string {
    if (!event.all_day) return formatEventWhen(new Date(event.start), new Date(event.end), language)
    const lastDay = addCalendarDays(event.end, -1)
    const range = lastDay === event.start
      ? formatEventDay(localDate(event.start), language)
      : `${formatEventDay(localDate(event.start), language)} – ${formatEventDay(localDate(lastDay), language)}`
    return `${range} · ${tr("allDay")}`
  }

  return (
    <CalendarEventPopover
      anchor={anchor}
      open={open}
      onOpenChange={onOpenChange}
      locked={busy}
      closeLabel={tr("close")}
      swatchClassName="bg-sky-500"
      title={event.summary ?? tr("untitled")}
      when={whenLabel()}
      subtitle={isBlock ? tr("availabilityBlock") : undefined}
      actions={
        <>
          {deletable && (
            <CalendarEventAction
              label={tr(isBlock ? "unblock" : "delete")}
              aria-pressed={confirming}
              disabled={busy}
              onClick={() => { setConfirming((value) => !value); setError("") }}
            >
              <Trash2 aria-hidden="true" className="size-5" />
            </CalendarEventAction>
          )}
          {event.html_link && (
            <CalendarEventAction label={tr("viewGoogle")} asChild>
              <a href={event.html_link} target="_blank" rel="noopener noreferrer">
                <ExternalLink aria-hidden="true" className="size-5" />
              </a>
            </CalendarEventAction>
          )}
        </>
      }
    >
      {event.recurring && <p className="rounded-md bg-muted/50 px-3 py-2 text-sm text-muted-foreground">{tr("recurringNote")}</p>}

      {!deletable && (
        <p className="text-sm text-muted-foreground">{tr(event.kind === "event" ? "notDeletable" : "managedElsewhere")}</p>
      )}

      {deletable && confirming && (
        <div className="space-y-3 rounded-md bg-muted/50 p-3">
          <p className="text-sm">{tr(isBlock ? "confirmUnblock" : "confirmDelete")}</p>
          {!isBlock && event.has_guests && (
            <div className="flex min-h-11 items-center gap-2">
              <Checkbox id={`${id}-notify`} checked={notifyGuests} disabled={busy} onCheckedChange={(checked) => setNotifyGuests(checked === true)} />
              <label htmlFor={`${id}-notify`} className="text-sm">{tr("notifyGuests")}</label>
            </div>
          )}
          <div className="flex flex-wrap justify-end gap-2">
            <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => { setConfirming(false); setError("") }}>{tr("cancel")}</Button>
            <Button type="button" variant="destructive" className="min-h-11" disabled={busy} aria-busy={busy} onClick={() => void remove()}>
              {busy ? tr("deleting") : isBlock ? tr("unblock") : tr("confirm")}
            </Button>
          </div>
        </div>
      )}

      {error && <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</p>}
    </CalendarEventPopover>
  )
}
