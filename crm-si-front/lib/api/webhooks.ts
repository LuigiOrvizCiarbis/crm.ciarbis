import { getAuthToken } from "./auth-token"

export interface WebhookEndpoint {
  id: number
  name: string
  slug: string
  target: string
  enabled: boolean
  api_key_prefix: string | null
  has_signing_secret: boolean
  public_url: string
  last_received_at: string | null
  // Solo presente en la respuesta de create/rotate (se muestra una única vez).
  api_key?: string
}

export interface WebhookEndpointInput {
  name: string
  // Write-only: solo se manda si el usuario cargó uno.
  signing_secret?: string
}

export interface WebhookEndpointUpdateInput {
  name?: string
  enabled?: boolean
  // Write-only. "" = quitar el signing secret; ausente = sin cambio.
  signing_secret?: string
}

export type WebhookContactFieldType =
  | "text"
  | "number"
  | "currency"
  | "date"
  | "boolean"
  | "select"
  | "multi_select"
  | "email"
  | "url"
  | "phone"
  | "repeater"

export interface WebhookContactField {
  key: string
  label: string
  type: WebhookContactFieldType
  options: {
    choices?: string[]
    fields?: Array<{ key?: string; label: string; type: string; options?: { choices?: string[] } | null; is_active?: boolean }>
  } | null
  is_required: boolean
}

export interface WebhookDelivery {
  id: number
  status:
    | "received"
    | "queued"
    | "processing"
    | "processed"
    | "partial"
    | "failed"
    | "rejected"
  http_status: number | null
  created: number | null
  updated: number | null
  failed: number | null
  created_at: string | null
}

export interface WebhookDeliveryError {
  index: number
  external_id: string | number | null
  message: string
}

export interface WebhookDeliveryDetail extends WebhookDelivery {
  payload: unknown
  result: {
    created: number
    updated: number
    failed: number
    errors: WebhookDeliveryError[]
  } | null
  error: string | null
  ip: string | null
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

function authHeaders(): Record<string, string> | null {
  const token = getAuthToken()
  if (!token) return null
  return { Authorization: `Bearer ${token}`, Accept: "application/json" }
}

export async function listWebhookEndpoints(): Promise<WebhookEndpoint[]> {
  const headers = authHeaders()
  if (!headers) return []

  const res = await fetch("/api/webhook-endpoints", {
    headers,
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data ?? []
}

export async function getWebhookContactFields(): Promise<WebhookContactField[]> {
  const headers = authHeaders()
  if (!headers) return []

  const res = await fetch("/api/webhook-endpoints/schema", {
    headers,
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data ?? []
}

export async function createWebhookEndpoint(
  input: WebhookEndpointInput,
): Promise<{ data?: WebhookEndpoint; error?: string }> {
  const headers = authHeaders()
  if (!headers) return { error: "No auth" }

  const res = await fetch("/api/webhook-endpoints", {
    method: "POST",
    headers: { ...headers, "Content-Type": "application/json" },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function updateWebhookEndpoint(
  id: number,
  input: WebhookEndpointUpdateInput,
): Promise<{ data?: WebhookEndpoint; error?: string }> {
  const headers = authHeaders()
  if (!headers) return { error: "No auth" }

  const res = await fetch(`/api/webhook-endpoints/${id}`, {
    method: "PUT",
    headers: { ...headers, "Content-Type": "application/json" },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function deleteWebhookEndpoint(
  id: number,
): Promise<{ ok: boolean; error?: string }> {
  const headers = authHeaders()
  if (!headers) return { ok: false, error: "No auth" }

  const res = await fetch(`/api/webhook-endpoints/${id}`, {
    method: "DELETE",
    headers,
  })

  if (!res.ok) {
    const json = await res.json().catch(() => ({}))
    return { ok: false, error: extractError(json) }
  }
  return { ok: true }
}

export async function rotateWebhookKey(
  id: number,
): Promise<{ data?: WebhookEndpoint; error?: string }> {
  const headers = authHeaders()
  if (!headers) return { error: "No auth" }

  const res = await fetch(`/api/webhook-endpoints/${id}/rotate-key`, {
    method: "POST",
    headers,
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function listWebhookDeliveries(
  id: number,
  page = 1,
): Promise<Paginated<WebhookDelivery> | null> {
  const headers = authHeaders()
  if (!headers) return null

  const res = await fetch(
    `/api/webhook-endpoints/${id}/deliveries?page=${page}`,
    { headers, cache: "no-store" },
  )

  if (!res.ok) return null
  return (await res.json()) as Paginated<WebhookDelivery>
}

export async function getWebhookDelivery(
  id: number,
  deliveryId: number,
): Promise<WebhookDeliveryDetail | null> {
  const headers = authHeaders()
  if (!headers) return null

  const res = await fetch(
    `/api/webhook-endpoints/${id}/deliveries/${deliveryId}`,
    { headers, cache: "no-store" },
  )

  if (!res.ok) return null
  const json = await res.json()
  return json.data ?? null
}

function extractError(json: any): string {
  if (json?.errors && typeof json.errors === "object") {
    const first = Object.values(json.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return json?.message || "Error"
}
