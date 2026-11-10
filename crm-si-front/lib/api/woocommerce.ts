export interface WooConfig {
  store_url: string | null
  enabled: boolean
  has_credentials: boolean
  last_synced_at: string | null
}

export interface WooConfigInput {
  store_url: string
  enabled: boolean
  // Write-only: solo se mandan si el usuario cargó unas nuevas.
  consumer_key?: string
  consumer_secret?: string
}

export type WooTestErrorCode =
  | "invalid_credentials"
  | "invalid_url"
  | "unreachable"
  | "unknown"

export interface WooTestResult {
  ok: boolean
  error_code: WooTestErrorCode | null
  error_message: string | null
}

export interface WooTestInput {
  store_url: string
  consumer_key?: string
  consumer_secret?: string
}

export interface WooSyncResult {
  created: number
  updated: number
  total: number
  last_synced_at: string | null
}

export async function getWooConfig(): Promise<WooConfig | null> {
  const res = await fetch("/api/woocommerce-config", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return null
  const json = await res.json()
  return json.data ?? null
}

export async function updateWooConfig(
  input: WooConfigInput,
): Promise<{ data?: WooConfig; error?: string }> {
  const res = await fetch("/api/woocommerce-config", {
    method: "PUT",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

/**
 * Prueba la conexión con la tienda. Si no se pudo contactar el backend, devuelve
 * un resultado con error_code "unknown".
 */
export async function testWooConfig(input: WooTestInput): Promise<WooTestResult> {
  const res = await fetch("/api/woocommerce-config/test", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  const data = json?.data

  if (data && typeof data.ok === "boolean") {
    return data as WooTestResult
  }

  return {
    ok: false,
    error_code: "unknown",
    error_message: extractError(json),
  }
}

export async function syncWooProducts(): Promise<{
  data?: WooSyncResult
  error?: string
}> {
  const res = await fetch("/api/woocommerce-config/sync", {
    method: "POST",
    headers: { Accept: "application/json" },
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

function extractError(json: any): string {
  if (json?.errors && typeof json.errors === "object") {
    const first = Object.values(json.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return json?.message || "Error"
}
