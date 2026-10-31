import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export type AutomationStatus = "draft" | "active" | "paused"
export type AutomationRunStatus = "scheduled" | "queued" | "running" | "succeeded" | "skipped" | "failed" | "needs_review" | "cancelled"

export interface AutomationCondition {
  field?: string
  operator?: string
  value?: unknown
  conditions?: AutomationCondition[]
  operator_group?: "AND" | "OR"
  [key: string]: unknown
}

export interface AutomationAction {
  id?: number
  position?: number
  type: "whatsapp_template" | "instagram_private_reply"
  config: {
    channel_id?: number
    template_id?: number
    parameters?: Array<{ component?: string; name?: string; source: "literal" | "field" | "media_asset"; value?: string; path?: string; fallback?: string; media_asset_id?: number }>
    message?: string
  }
}

export interface AutomationRule {
  id: number
  name: string
  status: AutomationStatus
  version: number
  trigger_type: string
  trigger_config: Record<string, unknown>
  conditions: Record<string, unknown> | null
  timezone: string
  activated_at: string | null
  actions: AutomationAction[]
  runs_count?: number
  updated_at: string
}

export interface AutomationRun {
  id: number
  status: AutomationRunStatus
  subject_type: "contact" | "conversation" | "instagram_comment"
  subject_id: number
  scheduled_for: string | null
  finished_at: string | null
  attempts: number
  error: string | null
  action_runs?: Array<{
    id: number
    position: number
    status: AutomationRunStatus
    attempts: number
    delivery_key: string | null
    delivery_started_at: string | null
    delivery_confirmed_at: string | null
    error: string | null
  }>
  created_at: string
}

export interface AutomationPayload {
  name: string
  trigger_type: string
  trigger_config: Record<string, unknown>
  conditions: Record<string, unknown> | null
  timezone: string
  actions: AutomationAction[]
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")
  const response = await fetch(path, {
    ...init,
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}`, ...init.headers },
  })
  const data = response.status === 204 ? null : await response.json().catch(() => ({}))
  if (!response.ok) throwApiError(response.status, data, "Error al procesar la automatización")
  return (data?.data ?? data) as T
}

export const getAutomations = async () => {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")

  const all: AutomationRule[] = []
  let page = 1
  let lastPage = 1

  do {
    const response = await fetch(`/api/automations?per_page=100&page=${page}`, {
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
    })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throwApiError(response.status, payload, "Error al cargar automatizaciones")

    all.push(...(payload.data ?? []))
    lastPage = Number(payload.meta?.last_page ?? page)
    page += 1
  } while (page <= lastPage)

  return all
}
export const createAutomation = (payload: AutomationPayload) => request<AutomationRule>("/api/automations", { method: "POST", body: JSON.stringify(payload) })
export const updateAutomation = (id: number, payload: AutomationPayload) => request<AutomationRule>(`/api/automations/${id}`, { method: "PUT", body: JSON.stringify(payload) })
export const deleteAutomation = (id: number) => request<void>(`/api/automations/${id}`, { method: "DELETE" })
export const activateAutomation = (id: number) => request<AutomationRule>(`/api/automations/${id}/activate`, { method: "POST", body: "{}" })
export const pauseAutomation = (id: number) => request<AutomationRule>(`/api/automations/${id}/pause`, { method: "POST", body: "{}" })
export const previewAutomation = (id: number, subject_type: string, subject_id: number) => request<Record<string, unknown>>(`/api/automations/${id}/preview`, { method: "POST", body: JSON.stringify({ subject_type, subject_id }) })
export const getAutomationRuns = async (id: number) => {
  const data = await request<{ data: AutomationRun[] } | AutomationRun[]>(`/api/automations/${id}/runs`)
  return Array.isArray(data) ? data : data.data
}
export const retryAutomationRun = (id: number) => request<AutomationRun>(`/api/automation-runs/${id}/retry`, { method: "POST", body: "{}" })
