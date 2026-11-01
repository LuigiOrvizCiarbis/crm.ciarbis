import { getAuthToken } from "./auth-token"
import { extractError } from "./ai-config"

export type AiSchedulingMode = "auto" | "approval" | "criteria"

export type Weekday = "mon" | "tue" | "wed" | "thu" | "fri" | "sat" | "sun"

export interface TimeRange {
  start: string
  end: string
}

export interface AiSchedulingCalendarUser {
  id: number
  name: string
  email: string
}

export interface AiSchedulingConfig {
  enabled: boolean
  mode: AiSchedulingMode
  criteria: string | null
  default_user_id: number | null
  timezone: string
  meeting_duration_minutes: number
  slot_interval_minutes: number
  min_notice_hours: number
  max_days_ahead: number
  working_hours: Record<Weekday, TimeRange[]>
  calendar_users: AiSchedulingCalendarUser[]
}

export type AiSchedulingConfigInput = Omit<AiSchedulingConfig, "calendar_users">

export async function getAiSchedulingConfig(): Promise<AiSchedulingConfig | null> {
  const token = getAuthToken()
  if (!token) return null

  const res = await fetch("/api/ai-config/scheduling", {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return null
  const json = await res.json()
  return json.data ?? null
}

export async function updateAiSchedulingConfig(
  input: AiSchedulingConfigInput,
): Promise<{ data?: AiSchedulingConfig; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch("/api/ai-config/scheduling", {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}
