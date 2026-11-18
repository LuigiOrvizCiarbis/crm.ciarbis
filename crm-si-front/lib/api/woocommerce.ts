export interface WooConfig {
  store_url: string | null
  enabled: boolean
  has_credentials: boolean
  last_synced_at: string | null
  sync: WooSyncState | null
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
  deactivated: number
  variants: number
  total: number
  // Campos del CRM con el mismo nombre pero otro tipo: el sync no los completa.
  skipped_fields: string[]
}

export interface WooSyncState {
  status: "running" | "completed" | "failed"
  // En curso pero sin avance hace rato: se puede volver a iniciar.
  stale: boolean
  started_at: string | null
  finished_at: string | null
  result: WooSyncResult | null
  error: string | null
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

/**
 * Encola el sync del catálogo. Si ya hay uno en curso (409) también devuelve
 * la config, para que la UI siga mostrando su progreso.
 */
export async function syncWooProducts(): Promise<{
  data?: WooConfig
  error?: string
}> {
  const res = await fetch("/api/woocommerce-config/sync", {
    method: "POST",
    headers: { Accept: "application/json" },
  })

  const json = await res.json().catch(() => ({}))
  if (res.ok || (res.status === 409 && json?.data)) return { data: json.data }
  return { error: extractError(json) }
}

function extractError(json: any): string {
  if (json?.errors && typeof json.errors === "object") {
    const first = Object.values(json.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return json?.message || "Error"
}
