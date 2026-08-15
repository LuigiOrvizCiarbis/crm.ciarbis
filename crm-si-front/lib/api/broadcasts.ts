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
