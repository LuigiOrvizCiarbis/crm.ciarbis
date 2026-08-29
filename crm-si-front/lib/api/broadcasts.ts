import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export type BroadcastStatus = "scheduled" | "processing" | "completed" | "partial" | "failed" | "cancelled"

export interface BroadcastFilter {
  field: string
  operator: "equals" | "not_equals" | "contains"
  value: string
}

export interface BroadcastAudienceFilters {
  pipeline_stage_id?: number
  tag_ids?: number[]
  custom_filters?: BroadcastFilter[]
}

export interface BroadcastCampaign {
  id: number
  name: string
  status: BroadcastStatus
  channel: { id: number; name: string; type: number }
  template: { id: number; name: string; language: string }
  audience_filters: BroadcastAudienceFilters
  audience_count: number
  sent_count: number
  error_count: number
  pending_count: number
  estimated_cost_usd: number
  actual_cost_usd: number
  interval_seconds: number
  scheduled_at: string
  started_at: string | null
  completed_at: string | null
  created_at: string
  results_tracking_version?: number | null
}

export type BroadcastRecipientResultStatus = "pending" | "accepted_unconfirmed" | "delivered" | "read" | "failed"
export interface BroadcastRecipientResult {
  id: number
  conversation_id: number
  contact: { id: number; name: string; phone: string }
  status: BroadcastRecipientResultStatus
  status_label: string
  queued_at: string | null
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
  failure: { message: string; code: string | null; details: unknown } | null
  interaction: { type: string; value: string | null; content: string | null; occurred_at: string } | null
}
export interface BroadcastResultsSummary {
  audience_count: number
  accepted_count: number
  delivered_count: number
  read_count: number
  failed_count: number
  pending_count: number
  unconfirmed_count: number
  interacted_count: number
}
export interface BroadcastResults {
  results_available: boolean
  campaign_id?: number
  campaign?: BroadcastCampaign
  summary?: BroadcastResultsSummary
}

export interface BroadcastPayload {
  name: string
  channel_id: number
  template_id: number
  components: unknown[]
  filters: BroadcastAudienceFilters
  launch: "now" | "scheduled"
  scheduled_at?: string
  interval_seconds: 0 | 15 | 30 | 60 | 120
}

function token(): string {
  const value = getAuthToken()
  if (!value) throw new Error("No authentication token found")
  return value
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    headers: {
      Authorization: `Bearer ${token()}`,
      Accept: "application/json",
      "Content-Type": "application/json",
      ...options.headers,
    },
    cache: "no-store",
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throwApiError(response.status, payload, "Error al procesar la difusión")
  return payload as T
}

export async function getBroadcasts(): Promise<BroadcastCampaign[]> {
  const payload = await request<{ data: BroadcastCampaign[] }>("/api/broadcasts?per_page=100")
  return payload.data
}

/**
 * Techo de Meta para envíos fuera de la ventana de atención en 24h.
 * Se comparte entre todos los números de la cartera, así que `limit` es un
 * máximo compartido y no un cupo exclusivo de este canal.
 * `known: false` significa que no se pudo leer, no que no haya límite.
 */
export interface BroadcastMessagingLimit {
  known: boolean
  tier: string | null
  limit: number | null
  unlimited: boolean
  exceeded: boolean
}

export interface BroadcastEstimate {
  audience_count: number
  /** Contactos de EE.UU. descartados: Meta no entrega marketing a ese país. */
  excluded_us_count: number
  estimated_cost_usd: number
  capped: boolean
  messaging_limit: BroadcastMessagingLimit
}

export async function estimateBroadcast(payload: BroadcastPayload): Promise<BroadcastEstimate> {
  const result = await request<{ data: BroadcastEstimate }>("/api/broadcasts/estimate", {
    method: "POST",
    body: JSON.stringify(payload),
  })
  return result.data
}

export async function createBroadcast(payload: BroadcastPayload): Promise<BroadcastCampaign> {
  const result = await request<{ data: BroadcastCampaign }>("/api/broadcasts", {
    method: "POST",
    body: JSON.stringify(payload),
  })
  return result.data
}

export async function getBroadcastResults(id: number): Promise<BroadcastResults> {
  const result = await request<{ data: BroadcastResults }>(`/api/broadcasts/${id}/results`)
  return result.data
}

export async function getBroadcastRecipients(id: number, params: { page?: number; status?: string; search?: string; interaction?: boolean } = {}) {
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => value !== undefined && query.set(key, String(value)))
  return request<{ data: BroadcastRecipientResult[]; meta: { current_page: number; last_page: number; total: number } }>(`/api/broadcasts/${id}/recipients?${query}`)
}

export async function getBroadcastRecipient(id: number, recipientId: number) {
  return request<{ data: BroadcastRecipientResult; history: Array<{ type: string; value: string | null; content: string | null; occurred_at: string }> }>(`/api/broadcasts/${id}/recipients/${recipientId}`)
}
