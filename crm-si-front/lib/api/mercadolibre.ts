import { workspaceHeaders } from "./auth-token"

export type MercadoLibreConnectionStatus = "disconnected" | "connected" | "needs_reauth"
export type MercadoLibreListingType = "gold_special" | "gold_pro" | "free"
export type MercadoLibreCondition = "new" | "used"

export interface MercadoLibreSummary {
  synced: number
  pending: number
  paused: number
  needs_attention: number
}

export interface MercadoLibreConnection {
  configured: boolean
  status: MercadoLibreConnectionStatus
  nickname: string | null
  site_id: string | null
  currency_id: string | null
  connected_at: string | null
  sync_enabled: boolean
  listing_type_id: MercadoLibreListingType
  condition: MercadoLibreCondition
  /** Stock que se manda cuando el producto no maneja stock (1..9999). */
  default_quantity: number
  last_full_sync_at: string | null
  summary: MercadoLibreSummary
}

export interface MercadoLibreConnectionInput {
  sync_enabled?: boolean
  listing_type_id?: MercadoLibreListingType
  condition?: MercadoLibreCondition
  default_quantity?: number
}

export type MercadoLibreListingStatus = "pending" | "synced" | "paused" | "error" | "needs_attributes"
export type MercadoLibreListingFilter = "needs_attention" | "synced" | "pending" | "paused"

export interface MercadoLibreMissing {
  /** Id de atributo de ML, o los especiales "CATEGORY", "PICTURE", "PRICE". */
  id: string
  name: string
}

export interface MercadoLibreListing {
  id: number
  product_id: number
  product_name: string
  variant_id: number | null
  variant_label: string | null
  status: MercadoLibreListingStatus
  ml_item_id: string | null
  ml_status: string | null
  permalink: string | null
  error: string | null
  missing: MercadoLibreMissing[]
  synced_at: string | null
}

export interface MercadoLibreListingsPage {
  data: MercadoLibreListing[]
  meta: { current_page: number; last_page: number; total: number }
}

export interface MercadoLibreCategory {
  category_id: string
  category_name: string
  domain_name: string | null
}

export interface MercadoLibreAttribute {
  id: string
  name: string
  value_type: string
  required: boolean
  values: { id: string; name: string }[]
  allowed_units: { id: string; name: string }[]
  max_length: number | null
  hint: string | null
}

export interface MercadoLibreAttributeValue {
  id: string
  value_id: string | null
  value_name: string
}

export interface ProductMercadoLibre {
  connected: boolean
  enabled: boolean
  category_id: string | null
  category_name: string | null
  category_predicted: boolean
  attributes: MercadoLibreAttributeValue[]
  listings: MercadoLibreListing[]
}

export interface ProductMercadoLibreInput {
  enabled: boolean
  category_id: string | null
  attributes: MercadoLibreAttributeValue[]
}

export type FieldErrors = Record<string, string[]>

const JSON_HEADERS = { "Content-Type": "application/json", Accept: "application/json" }

export async function getMercadoLibreConnection(): Promise<MercadoLibreConnection | null> {
  const res = await fetch("/api/mercadolibre/connection", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return null
  const json = await res.json().catch(() => ({}))
  return json.data ?? null
}

export async function updateMercadoLibreConnection(
  input: MercadoLibreConnectionInput,
): Promise<{ data?: MercadoLibreConnection; error?: string }> {
  const res = await fetch("/api/mercadolibre/connection", {
    method: "PUT",
    headers: JSON_HEADERS,
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function disconnectMercadoLibre(): Promise<{ error?: string }> {
  const res = await fetch("/api/mercadolibre/connection", {
    method: "DELETE",
    headers: { Accept: "application/json" },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return {}
}

export async function getMercadoLibreAuthorizationUrl(): Promise<{ data?: string; error?: string }> {
  const res = await fetch("/api/mercadolibre/authorization-url", {
    method: "POST",
    headers: { Accept: "application/json", ...workspaceHeaders() },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.url }
}

export async function syncMercadoLibre(): Promise<{ data?: MercadoLibreConnection; error?: string }> {
  const res = await fetch("/api/mercadolibre/sync", {
    method: "POST",
    headers: { Accept: "application/json" },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function getMercadoLibreListings(
  status: MercadoLibreListingFilter,
  page = 1,
): Promise<{ data?: MercadoLibreListingsPage; error?: string }> {
  const params = new URLSearchParams({ status, page: String(page) })
  const res = await fetch(`/api/mercadolibre/listings?${params}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return {
    data: {
      data: json.data ?? [],
      meta: json.meta ?? { current_page: page, last_page: page, total: 0 },
    },
  }
}

export async function searchMercadoLibreCategories(
  q: string,
  signal?: AbortSignal,
): Promise<{ data?: MercadoLibreCategory[]; error?: string }> {
  const params = new URLSearchParams({ q })
  const res = await fetch(`/api/mercadolibre/categories?${params}`, {
    headers: { Accept: "application/json" },
    signal,
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data ?? [] }
}

export async function getMercadoLibreCategoryAttributes(
  categoryId: string,
): Promise<{ data?: MercadoLibreAttribute[]; error?: string }> {
  const res = await fetch(
    `/api/mercadolibre/categories/${encodeURIComponent(categoryId)}/attributes`,
    { headers: { Accept: "application/json" } },
  )

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data ?? [] }
}

export async function getProductMercadoLibre(
  productId: number | string,
): Promise<{ data?: ProductMercadoLibre; error?: string }> {
  const res = await fetch(`/api/products/${productId}/mercadolibre`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function updateProductMercadoLibre(
  productId: number | string,
  input: ProductMercadoLibreInput,
): Promise<{ data?: ProductMercadoLibre; error?: string; fieldErrors?: FieldErrors }> {
  const res = await fetch(`/api/products/${productId}/mercadolibre`, {
    method: "PUT",
    headers: JSON_HEADERS,
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) {
    const errors = json?.errors
    return {
      error: extractError(json),
      ...(errors && typeof errors === "object" ? { fieldErrors: errors as FieldErrors } : {}),
    }
  }
  return { data: json.data }
}

function extractError(json: unknown): string {
  if (!json || typeof json !== "object") return "Error"
  const body = json as { errors?: unknown; message?: unknown }
  if (body.errors && typeof body.errors === "object") {
    const first = Object.values(body.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return typeof body.message === "string" && body.message ? body.message : "Error"
}
