import { getAuthToken, workspaceHeaders } from "./auth-token"

export interface CalendarAvailabilityBlock {
  id: string
  all_day: boolean
  start: string
  end: string
  timezone: string
  html_link: string | null
}

export interface CalendarBlockList {
  google_email: string
  timezone: string
  from_date: string
  through_date: string
  blocks: CalendarAvailabilityBlock[]
}

type CreateCalendarBlockBase = {
  request_id: string
  timezone: string
  confirm_overlap?: boolean
}

export type CreateCalendarBlockInput = CreateCalendarBlockBase & (
  | { mode: "rest_of_day"; starts_at?: never; ends_at?: never; start_date?: never; end_date?: never }
  | { mode: "time_range"; starts_at: string; ends_at: string; start_date?: never; end_date?: never }
  | { mode: "full_days"; start_date: string; end_date: string; starts_at?: never; ends_at?: never }
)

export class CalendarBlockApiError extends Error {
  constructor(message: string, public readonly status: number, public readonly code?: string) {
    super(message)
    this.name = "CalendarBlockApiError"
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getAuthToken()
  if (!token) throw new CalendarBlockApiError("Tu sesión venció. Volvé a iniciar sesión.", 401)
  let response: Response
  try {
    response = await fetch(`/api/google-calendar/blocks${path}`, {
      ...options,
      cache: "no-store",
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        ...workspaceHeaders(),
        ...(options.body ? { "Content-Type": "application/json" } : {}),
      },
    })
  } catch {
    throw new CalendarBlockApiError(options.method === "POST"
      ? "No se pudo confirmar el bloqueo. Reintentá para comprobarlo."
      : "No se pudo consultar/actualizar Google Calendar. Volvé a intentar.", 0)
  }
  if (response.status === 204) return undefined as T
  const json = await response.json().catch(() => null)
  if (!response.ok) {
    const firstError = json?.errors && Object.values(json.errors)[0]
    const message = Array.isArray(firstError) ? String(firstError[0]) : json?.message
    throw new CalendarBlockApiError(message || "No se pudo consultar/actualizar Google Calendar. Volvé a intentar.", response.status, json?.code)
  }
  if (!json || typeof json !== "object" || !("data" in json)) throw new CalendarBlockApiError("No se pudo confirmar la respuesta de Google Calendar. Volvé a intentar.", 503)
  return json.data as T
}

export function listCalendarAvailabilityBlocks(range?: { from_date: string; through_date: string }): Promise<CalendarBlockList> {
  const query = range ? `?${new URLSearchParams(range)}` : ""
  return request<CalendarBlockList>(query)
}

export function createCalendarAvailabilityBlock(input: CreateCalendarBlockInput): Promise<CalendarAvailabilityBlock> {
  return request<CalendarAvailabilityBlock>("", { method: "POST", body: JSON.stringify(input) })
}

export function deleteCalendarAvailabilityBlock(eventId: string): Promise<void> {
  return request<void>(`/${encodeURIComponent(eventId)}`, { method: "DELETE" })
}
