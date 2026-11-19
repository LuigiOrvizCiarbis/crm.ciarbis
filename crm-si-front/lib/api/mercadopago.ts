export interface MercadoPagoConfig {
  has_credentials: boolean
  statement_descriptor: string | null
  last_verified_at: string | null
}

export interface MercadoPagoConfigInput {
  // Write-only: solo se manda si el usuario cargó uno nuevo.
  access_token?: string
  statement_descriptor: string | null
}

export interface MercadoPagoTestResult {
  ok: boolean
  nickname: string | null
  error?: string
}

export async function getMercadoPagoConfig(): Promise<MercadoPagoConfig | null> {
  const res = await fetch("/api/mercadopago-config", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return null
  const json = await res.json().catch(() => ({}))
  return json.data ?? null
}

export async function updateMercadoPagoConfig(
  input: MercadoPagoConfigInput,
): Promise<{ data?: MercadoPagoConfig; error?: string }> {
  const res = await fetch("/api/mercadopago-config", {
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

/** Verifica la credencial guardada, o la nueva si se pasa una. */
export async function testMercadoPagoConfig(accessToken?: string): Promise<MercadoPagoTestResult> {
  const res = await fetch("/api/mercadopago-config/test", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(accessToken ? { access_token: accessToken } : {}),
  })

  const json = await res.json().catch(() => ({}))
  const data = json?.data ?? json
  if (res.ok && data && typeof data.ok === "boolean") {
    return { ok: data.ok, nickname: data.nickname ?? null }
  }
  return { ok: false, nickname: null, error: extractError(json) }
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
