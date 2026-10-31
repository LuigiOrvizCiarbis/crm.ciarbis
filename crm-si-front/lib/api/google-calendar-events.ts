import { calendarRequest } from "./calendar-availability-blocks"

export type GoogleCalendarEventKind = "event" | "availability_block" | "crm_meeting"

export interface GoogleCalendarEvent {
  id: string
  summary: string | null
  all_day: boolean
  /** All-day: `yyyy-MM-dd`, end exclusive. Timed: RFC 3339 instant. */
  start: string
  end: string
  html_link: string | null
  kind: GoogleCalendarEventKind
  can_delete: boolean
  has_guests: boolean
  recurring: boolean
}

export interface GoogleCalendarEventList {
  google_email: string
  timezone: string
  events: GoogleCalendarEvent[]
}

/** Errors are thrown as `CalendarBlockApiError` (status + code). */
export function listGoogleCalendarEvents(range: { time_min: string; time_max: string }): Promise<GoogleCalendarEventList> {
  return calendarRequest<GoogleCalendarEventList>(`/api/google-calendar/events?${new URLSearchParams(range)}`)
}

export function deleteGoogleCalendarEvent(eventId: string, notifyGuests: boolean): Promise<void> {
  return calendarRequest<void>(
    `/api/google-calendar/events/${encodeURIComponent(eventId)}?notify_guests=${notifyGuests ? "1" : "0"}`,
    { method: "DELETE" },
  )
}
