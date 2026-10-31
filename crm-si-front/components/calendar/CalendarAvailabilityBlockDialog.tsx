"use client"

import { useEffect, useId, useRef, useState, type FormEvent } from "react"
import { formatInTimeZone } from "date-fns-tz"
import { CalendarOff, ExternalLink, Loader2, LockKeyhole } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { getWorkspaceId } from "@/lib/api/auth-token"
import { getGoogleCalendarAuthorizationUrl } from "@/lib/api/google-calendar"
import {
  CalendarBlockApiError,
  createCalendarAvailabilityBlock,
  deleteCalendarAvailabilityBlock,
  listCalendarAvailabilityBlocks,
  type CalendarAvailabilityBlock,
  type CalendarBlockList,
  type CreateCalendarBlockInput,
} from "@/lib/api/calendar-availability-blocks"

type Mode = CreateCalendarBlockInput["mode"]
type Range = { from_date: string; through_date: string }
type Context = { userId: number; workspaceId: number | null; activeWorkspaceId: number | null; tenantId: number | null }
type Session = { context: Context; generation: number }
type Fields = { startsAt: string; endsAt: string; startDate: string; endDate: string }

function currentContext(): Context | null {
  const { user, activeWorkspaceId } = useAuthStore.getState()
  return user ? {
    userId: user.id,
    workspaceId: getWorkspaceId() ?? activeWorkspaceId ?? user.tenant_id ?? null,
    activeWorkspaceId,
    tenantId: user.tenant_id ?? null,
  } : null
}

function sameContext(a: Context, b: Context | null): boolean {
  return Boolean(b && a.userId === b.userId && a.workspaceId === b.workspaceId
    && a.activeWorkspaceId === b.activeWorkspaceId && a.tenantId === b.tenantId)
}

// Calendar-date arithmetic only. UTC prevents the browser's timezone/DST from changing a date.
function addCalendarDays(value: string, days: number): string {
  const [year, month, day] = value.split("-").map(Number)
  const date = new Date(Date.UTC(year, month - 1, day + days))
  return date.toISOString().slice(0, 10)
}

function defaults(timezone: string, date?: string): Fields {
  const now = new Date()
  const nextMinute = new Date((Math.floor(now.getTime() / 60000) + 1) * 60000)
  const today = formatInTimeZone(now, timezone, "yyyy-MM-dd")
  const chosenDate = date && date >= today ? date : today
  return {
    startsAt: formatInTimeZone(nextMinute, timezone, "yyyy-MM-dd'T'HH:mm"),
    endsAt: formatInTimeZone(new Date(nextMinute.getTime() + 3600000), timezone, "yyyy-MM-dd'T'HH:mm"),
    startDate: chosenDate,
    endDate: chosenDate,
  }
}

export interface CalendarAvailabilityBlockDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialDate?: string
}

export function CalendarAvailabilityBlockDialog({ open, onOpenChange, initialDate }: CalendarAvailabilityBlockDialogProps) {
  const { t, language } = useTranslation()
  const { addToast } = useToast()
  const userId = useAuthStore((state) => state.user?.id)
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId)
  const tenantId = useAuthStore((state) => state.user?.tenant_id)
  const tr = (key: string, params?: Record<string, string | number>) => t(`calendarAvailability.${key}`, params)
  const id = useId()
  const callbackRef = useRef(onOpenChange)
  callbackRef.current = onOpenChange
  const openRef = useRef(open)
  openRef.current = open
  const sessionRef = useRef<Session | null>(null)
  const generationRef = useRef(0)
  const listGenerationRef = useRef(0)
  const initializedRef = useRef(false)
  const busyRef = useRef(false)
  const requestIdRef = useRef("")
  const [calendar, setCalendar] = useState<CalendarBlockList | null>(null)
  const [mode, setMode] = useState<Mode>("rest_of_day")
  const [fields, setFields] = useState<Fields>({ startsAt: "", endsAt: "", startDate: "", endDate: "" })
  const [range, setRange] = useState<Range>({ from_date: "", through_date: "" })
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState<"create" | "delete" | "oauth" | null>(null)
  const [loadError, setLoadError] = useState("")
  const [formError, setFormError] = useState("")
  const [deleteError, setDeleteError] = useState<{ id: string; message: string } | null>(null)
  const [connectionMissing, setConnectionMissing] = useState(false)
  const [timezoneChanged, setTimezoneChanged] = useState(false)
  const [overlap, setOverlap] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState<string | null>(null)
  const [dateAdjusted, setDateAdjusted] = useState(false)

  function reset() {
    generationRef.current += 1
    listGenerationRef.current += 1
    sessionRef.current = null
    initializedRef.current = false
    requestIdRef.current = ""
    busyRef.current = false
    setCalendar(null)
    setFields({ startsAt: "", endsAt: "", startDate: "", endDate: "" })
    setRange({ from_date: "", through_date: "" })
    setBusy(null)
    setLoading(false)
    setLoadError("")
    setFormError("")
    setDeleteError(null)
    setOverlap(false)
    setTimezoneChanged(false)
    setConnectionMissing(false)
    setDeleteConfirmation(null)
    setDateAdjusted(false)
  }

  function closeAndReset() {
    reset()
    callbackRef.current(false)
  }

  function isCurrent(session: Session): boolean {
    if (!openRef.current || sessionRef.current !== session || session.generation !== generationRef.current) return false
    if (!sameContext(session.context, currentContext())) {
      closeAndReset()
      return false
    }
    return true
  }

  function errorMessage(error: unknown, operation: "load" | "create" | "delete" | "oauth"): string {
    if (error instanceof CalendarBlockApiError) {
      if (error.code === "calendar_not_connected") return tr("errors.notConnected")
      if (error.code === "calendar_busy") return tr("errors.busy")
      if (error.code === "calendar_timezone_changed") return tr("errors.timezoneChanged")
      if (error.code === "block_request_conflict") return tr("errors.requestConflict")
      if (error.status === 401) return tr("errors.session")
      if (error.status === 403) return tr("errors.workspace")
      if (error.status === 422) return tr("errors.validation")
    }
    return tr(`errors.${operation}`)
  }

  function handleConnectionError(error: unknown) {
    if (error instanceof CalendarBlockApiError && error.code === "calendar_not_connected") setConnectionMissing(true)
  }

  async function load(session: Session, requestedRange?: Range, reloadTimezone = false) {
    if (!isCurrent(session)) return
    const sequence = ++listGenerationRef.current
    setLoading(true)
    setLoadError("")
    try {
      const result = await listCalendarAvailabilityBlocks(requestedRange)
      if (!isCurrent(session) || sequence !== listGenerationRef.current) return
      setCalendar(result)
      setRange({ from_date: result.from_date, through_date: result.through_date })
      setConnectionMissing(false)
      if (initializedRef.current && calendar && calendar.timezone !== result.timezone && !reloadTimezone) {
        setTimezoneChanged(true)
        setFormError(tr("errors.timezoneChanged"))
      }
      if (!initializedRef.current) {
        const next = defaults(result.timezone, initialDate)
        setMode(initialDate ? "full_days" : "rest_of_day")
        setFields(next)
        setDateAdjusted(Boolean(initialDate && initialDate < formatInTimeZone(new Date(), result.timezone, "yyyy-MM-dd")))
        requestIdRef.current = crypto.randomUUID()
        initializedRef.current = true
      }
      if (reloadTimezone) {
        setTimezoneChanged(false)
        setFormError("")
        setOverlap(false)
        requestIdRef.current = crypto.randomUUID()
      }
    } catch (error) {
      if (!isCurrent(session) || sequence !== listGenerationRef.current) return
      handleConnectionError(error)
      setLoadError(errorMessage(error, "load"))
    } finally {
      if (isCurrent(session) && sequence === listGenerationRef.current) setLoading(false)
    }
  }

  useEffect(() => {
    if (!open) {
      reset()
      return
    }
    if (sessionRef.current) {
      if (!sameContext(sessionRef.current.context, currentContext())) closeAndReset()
      return
    }
    const context = currentContext()
    if (!context) {
      closeAndReset()
      return
    }
    const session = { context, generation: ++generationRef.current }
    sessionRef.current = session
    void load(session)
    // Context changes close this personal-calendar session instead of reusing its intent.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, userId, activeWorkspaceId, tenantId])

  useEffect(() => () => {
    generationRef.current += 1
    sessionRef.current = null
  }, [])

  function changeIntent(nextMode: Mode, nextFields = fields) {
    if (nextMode === mode && nextFields.startsAt === fields.startsAt && nextFields.endsAt === fields.endsAt
      && nextFields.startDate === fields.startDate && nextFields.endDate === fields.endDate) return
    requestIdRef.current = crypto.randomUUID()
    setMode(nextMode)
    setFields(nextFields)
    setOverlap(false)
    setFormError("")
    setDateAdjusted(false)
  }

  function changeField(key: keyof Fields, value: string) {
    changeIntent(mode, { ...fields, [key]: value })
  }

  async function create(confirmOverlap = false) {
    const session = sessionRef.current
    if (!session || busyRef.current || !calendar || connectionMissing || timezoneChanged || loading || !isCurrent(session)) return
    const base = { request_id: requestIdRef.current, timezone: calendar.timezone, confirm_overlap: confirmOverlap }
    const input: CreateCalendarBlockInput = mode === "time_range"
      ? { ...base, mode, starts_at: fields.startsAt.replace("T", " "), ends_at: fields.endsAt.replace("T", " ") }
      : mode === "full_days" ? { ...base, mode, start_date: fields.startDate, end_date: fields.endDate }
        : { ...base, mode }
    busyRef.current = true
    setBusy("create")
    setFormError("")
    try {
      if (!isCurrent(session)) return
      const block = await createCalendarAvailabilityBlock(input)
      if (!isCurrent(session)) return
      requestIdRef.current = crypto.randomUUID()
      setFields(defaults(calendar.timezone))
      setOverlap(false)
      setDateAdjusted(false)
      addToast({ type: "success", title: tr("created") })
      const startDate = block.all_day ? block.start : formatInTimeZone(new Date(block.start), block.timezone, "yyyy-MM-dd")
      const nextRange = startDate < calendar.from_date || startDate > calendar.through_date
        ? { from_date: startDate, through_date: addCalendarDays(startDate, 30) }
        : { from_date: calendar.from_date, through_date: calendar.through_date }
      await load(session, nextRange)
    } catch (error) {
      if (!isCurrent(session)) return
      handleConnectionError(error)
      if (error instanceof CalendarBlockApiError && error.code === "overlap_confirmation_required") {
        setOverlap(true)
      } else {
        if (error instanceof CalendarBlockApiError && error.code === "calendar_timezone_changed") setTimezoneChanged(true)
        setFormError(errorMessage(error, "create"))
      }
    } finally {
      if (isCurrent(session)) {
        busyRef.current = false
        setBusy(null)
      }
    }
  }

  async function remove(eventId: string) {
    const session = sessionRef.current
    if (!session || busyRef.current || !isCurrent(session)) return
    busyRef.current = true
    setBusy("delete")
    setDeleteError(null)
    try {
      if (!isCurrent(session)) return
      await deleteCalendarAvailabilityBlock(eventId)
      if (!isCurrent(session)) return
      setCalendar((previous) => previous ? { ...previous, blocks: previous.blocks.filter((block) => block.id !== eventId) } : previous)
      setDeleteConfirmation(null)
      addToast({ type: "success", title: tr("deleted") })
    } catch (error) {
      if (!isCurrent(session)) return
      handleConnectionError(error)
      setDeleteError({ id: eventId, message: errorMessage(error, "delete") })
    } finally {
      if (isCurrent(session)) {
        busyRef.current = false
        setBusy(null)
      }
    }
  }

  async function connect() {
    const session = sessionRef.current
    if (!session || busyRef.current || !isCurrent(session)) return
    busyRef.current = true
    setBusy("oauth")
    setLoadError("")
    try {
      if (!isCurrent(session)) return
      const result = await getGoogleCalendarAuthorizationUrl()
      if (!isCurrent(session)) return
      if (!result.data || result.error) throw new Error("authorization_failed")
      window.location.assign(result.data)
    } catch {
      if (isCurrent(session)) setLoadError(tr("errors.oauth"))
    } finally {
      if (isCurrent(session)) {
        busyRef.current = false
        setBusy(null)
      }
    }
  }

  function consult(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busyRef.current || loading) return
    const session = sessionRef.current
    if (!session || !isCurrent(session)) return
    const days = (Date.parse(`${range.through_date}T00:00:00Z`) - Date.parse(`${range.from_date}T00:00:00Z`)) / 86400000 + 1
    if (!Number.isFinite(days) || days < 1 || days > 93) {
      setLoadError(tr("errors.range"))
      return
    }
    void load(session, range)
  }

  function blockLabel(block: CalendarAvailabilityBlock): string {
    if (block.all_day) {
      const lastDay = addCalendarDays(block.end, -1)
      return `${block.start}${lastDay === block.start ? "" : ` → ${lastDay}`} · ${tr("allDay")}`
    }
    const locale = language === "en" ? "en-US" : "es-AR"
    const formatter = new Intl.DateTimeFormat(locale, { timeZone: block.timezone, dateStyle: "medium", timeStyle: "short" })
    return `${formatter.format(new Date(block.start))} → ${formatter.format(new Date(block.end))}`
  }

  const disabled = Boolean(busy) || loading || !calendar || connectionMissing || timezoneChanged
  const today = calendar ? formatInTimeZone(new Date(), calendar.timezone, "yyyy-MM-dd") : undefined

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!busyRef.current && !next) closeAndReset() }}>
      <DialogContent className="max-h-[90dvh] w-[calc(100%_-_1rem)] overflow-y-auto rounded-lg p-4 sm:max-w-2xl sm:p-6 [&>button]:min-h-11 [&>button]:min-w-11" onEscapeKeyDown={(event) => { if (busyRef.current) event.preventDefault() }} onInteractOutside={(event) => { if (busyRef.current) event.preventDefault() }}>
        <DialogHeader className="pr-10 text-left">
          <DialogTitle className="flex items-center gap-2"><CalendarOff aria-hidden="true" className="size-5 shrink-0 text-primary" />{tr("title")}</DialogTitle>
          <DialogDescription>{tr("description")}</DialogDescription>
        </DialogHeader>

        <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm">
          <p className="break-words font-medium">{calendar ? tr("calendar", { email: calendar.google_email, timezone: calendar.timezone }) : tr("calendarPending")}</p>
          <p className="mt-2 flex items-start gap-2 text-muted-foreground"><LockKeyhole aria-hidden="true" className="mt-0.5 size-4 shrink-0" />{tr("privacy")}</p>
        </div>

        {loading && <p role="status" className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" />{tr("loading")}</p>}
        {(loadError || connectionMissing) && (
          <div className="space-y-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
            <p role="alert" className="text-sm text-destructive">{connectionMissing ? tr("errors.notConnected") : loadError}</p>
            <div className="flex flex-wrap gap-2">
              {connectionMissing && <Button type="button" className="min-h-11" disabled={Boolean(busy)} onClick={() => void connect()}>{busy === "oauth" ? tr("connecting") : tr("connect")}</Button>}
              <Button type="button" className="min-h-11" variant="outline" disabled={Boolean(busy) || loading} onClick={() => { const session = sessionRef.current; if (session) void load(session, calendar ? { from_date: calendar.from_date, through_date: calendar.through_date } : undefined) }}>{tr("retryRead")}</Button>
            </div>
            {connectionMissing && loadError && loadError !== tr("errors.notConnected") && <p role="alert" className="text-sm text-destructive">{loadError}</p>}
          </div>
        )}

        <form onSubmit={(event) => { event.preventDefault(); if (!overlap) void create() }} className="space-y-4">
          <fieldset disabled={disabled} className="min-w-0 space-y-4">
            <legend className="mb-2 text-sm font-medium">{tr("presetLabel")}</legend>
            <div className="grid gap-2 sm:grid-cols-3" role="group" aria-label={tr("presetLabel")}>
              {(["rest_of_day", "time_range", "full_days"] as const).map((preset) => (
                <Button key={preset} type="button" variant={mode === preset ? "default" : "outline"} aria-pressed={mode === preset} className="min-h-11 whitespace-normal" onClick={() => changeIntent(preset)}>{tr(`presets.${preset}`)}</Button>
              ))}
            </div>
            {mode === "rest_of_day" && <p className="text-sm text-muted-foreground">{tr("todayHint")}</p>}
            {mode === "time_range" && (
              <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                <div className="min-w-0 space-y-1.5"><Label htmlFor={`${id}-starts`}>{tr("startsAt")}</Label><Input id={`${id}-starts`} className="h-11 min-w-0 max-w-full text-base sm:text-sm" type="datetime-local" required value={fields.startsAt} onChange={(event) => changeField("startsAt", event.target.value)} /></div>
                <div className="min-w-0 space-y-1.5"><Label htmlFor={`${id}-ends`}>{tr("endsAt")}</Label><Input id={`${id}-ends`} className="h-11 min-w-0 max-w-full text-base sm:text-sm" type="datetime-local" required value={fields.endsAt} onChange={(event) => changeField("endsAt", event.target.value)} /></div>
              </div>
            )}
            {mode === "full_days" && (
              <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                <div className="min-w-0 space-y-1.5"><Label htmlFor={`${id}-start-date`}>{tr("from")}</Label><Input id={`${id}-start-date`} className="h-11 min-w-0 max-w-full text-base sm:text-sm" type="date" required min={today} value={fields.startDate} onChange={(event) => changeField("startDate", event.target.value)} /></div>
                <div className="min-w-0 space-y-1.5"><Label htmlFor={`${id}-end-date`}>{tr("throughInclusive")}</Label><Input id={`${id}-end-date`} className="h-11 min-w-0 max-w-full text-base sm:text-sm" type="date" required min={fields.startDate || today} value={fields.endDate} onChange={(event) => changeField("endDate", event.target.value)} /></div>
              </div>
            )}
          </fieldset>
          {dateAdjusted && <p role="status" className="text-sm text-muted-foreground">{tr("dateAdjusted", { date: fields.startDate })}</p>}
          {formError && <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>}
          {timezoneChanged && <Button type="button" className="min-h-11" variant="outline" disabled={Boolean(busy) || loading} onClick={() => { const session = sessionRef.current; if (session) void load(session, calendar ? { from_date: calendar.from_date, through_date: calendar.through_date } : undefined, true) }}>{tr("reloadCalendar")}</Button>}
          {overlap ? (
            <div className="space-y-3 rounded-lg border bg-muted/40 p-4">
              <p role="alert" className="text-sm">{tr("overlap")}</p>
              <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" className="min-h-11" variant="outline" disabled={Boolean(busy)} onClick={() => setOverlap(false)}>{tr("back")}</Button>
                <Button type="button" className="min-h-11" disabled={disabled} onClick={() => void create(true)}>{busy === "create" ? tr("creating") : tr("createAnyway")}</Button>
              </div>
            </div>
          ) : <div className="flex justify-end"><Button type="submit" className="min-h-11 w-full sm:w-auto" disabled={disabled} aria-busy={busy === "create"}>{busy === "create" ? tr("creating") : tr("create")}</Button></div>}
        </form>

        <section className="mt-2 min-w-0 space-y-4 border-t pt-5" aria-labelledby={`${id}-list-title`}>
          <h3 id={`${id}-list-title`} className="font-semibold">{tr("myBlocks")}</h3>
          <form onSubmit={consult} className="grid min-w-0 gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div className="min-w-0 space-y-1.5"><Label htmlFor={`${id}-list-from`}>{tr("listFrom")}</Label><Input id={`${id}-list-from`} className="h-11 min-w-0 max-w-full text-base sm:text-sm" type="date" required value={range.from_date} disabled={!calendar || Boolean(busy) || loading || connectionMissing} onChange={(event) => setRange({ ...range, from_date: event.target.value })} /></div>
            <div className="min-w-0 space-y-1.5"><Label htmlFor={`${id}-list-through`}>{tr("listThrough")}</Label><Input id={`${id}-list-through`} className="h-11 min-w-0 max-w-full text-base sm:text-sm" type="date" required min={range.from_date} value={range.through_date} disabled={!calendar || Boolean(busy) || loading || connectionMissing} onChange={(event) => setRange({ ...range, through_date: event.target.value })} /></div>
            <Button type="submit" variant="outline" className="min-h-11" disabled={!calendar || Boolean(busy) || loading || connectionMissing}>{tr("consult")}</Button>
          </form>
          <p className="text-xs text-muted-foreground">{tr("rangeHint")}</p>
          {calendar && !connectionMissing && (
            <>
              <p className="text-xs text-muted-foreground">{tr("showingRange", { from: calendar.from_date, through: calendar.through_date })}</p>
              {calendar.blocks.length === 0 ? <p className="rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground">{tr("empty")}</p> : (
                <ul className="divide-y rounded-lg border px-3">
                  {calendar.blocks.map((block) => (
                    <li key={block.id} className="space-y-3 py-3">
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 space-y-1"><p className="break-words text-sm font-medium">{blockLabel(block)}</p><p className="text-xs text-muted-foreground">{block.timezone}</p></div>
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                          {block.html_link && <a href={block.html_link} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center gap-1 rounded-md px-2 text-sm text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">{tr("viewGoogle")}<ExternalLink aria-hidden="true" className="size-3.5" /></a>}
                          <Button type="button" variant="outline" className="min-h-11" disabled={Boolean(busy) || loading} onClick={() => { setDeleteConfirmation(block.id); setDeleteError(null) }}>{tr("delete")}</Button>
                        </div>
                      </div>
                      {deleteConfirmation === block.id && <div className="space-y-2 rounded-md bg-muted/50 p-3"><p className="text-sm">{tr("deleteConfirmation")}</p><div className="flex flex-wrap gap-2"><Button type="button" variant="outline" className="min-h-11" disabled={Boolean(busy)} onClick={() => setDeleteConfirmation(null)}>{tr("cancel")}</Button><Button type="button" className="min-h-11" disabled={Boolean(busy) || loading} onClick={() => void remove(block.id)}>{busy === "delete" ? tr("deleting") : tr("confirmDelete")}</Button></div></div>}
                      {deleteError?.id === block.id && <p role="alert" className="text-sm text-destructive">{deleteError.message}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </section>
        <div className="flex justify-end"><Button type="button" variant="outline" className="min-h-11" disabled={Boolean(busy)} onClick={closeAndReset}>{tr("close")}</Button></div>
      </DialogContent>
    </Dialog>
  )
}
