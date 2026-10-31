import { getAuthToken } from "./auth-token"

export type AiProviderId = "claude" | "openai"

export interface AiConfig {
  provider: AiProviderId | null
  model: string | null
  enabled: boolean
  system_prompt: string | null
  has_api_key: boolean
}

export interface AiConfigInput {
  provider: AiProviderId
  model?: string | null
  enabled: boolean
  system_prompt?: string | null
  // Write-only: solo se manda si el usuario cargó una nueva key.
  api_key?: string
}

export async function getAiConfig(): Promise<AiConfig | null> {
  const token = getAuthToken()
  if (!token) return null

  const res = await fetch("/api/ai-config", {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return null
  const json = await res.json()
  return json.data ?? null
}

/**
 * Trae los modelos disponibles para el proveedor + key ya guardados del tenant.
 * Devuelve [] si no hay key cargada o si el proveedor no responde.
 */
export async function getAiModels(): Promise<string[]> {
  const token = getAuthToken()
  if (!token) return []

  const res = await fetch("/api/ai-config/models", {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json().catch(() => ({}))
  return Array.isArray(json.data) ? json.data : []
}

export type AiTestErrorCode =
  | "invalid_key"
  | "no_credit"
  | "rate_limit"
  | "unknown"

export interface AiTestResult {
  ok: boolean
  prompt_tokens: number | null
  cache_min_tokens: number | null
  error_code: AiTestErrorCode | null
  error_message: string | null
  // Aviso (no bloqueante) si el modelo elegido no procesa imágenes. null si sí.
  model_vision_warning?: string | null
}

export interface AiTestInput {
  provider: AiProviderId
  model?: string | null
  system_prompt?: string | null
  // Solo se manda si el usuario cargó una key nueva sin guardar todavía.
  api_key?: string
}

/**
 * Prueba la conexión con el proveedor: valida la key, distingue key inválida /
 * saldo / rate limit y mide el system prompt. Si no se pudo contactar el
 * backend, devuelve un resultado con error_code "unknown".
 */
export async function testAiConfig(input: AiTestInput): Promise<AiTestResult> {
  const token = getAuthToken()
  if (!token) {
    return {
      ok: false,
      prompt_tokens: null,
      cache_min_tokens: null,
      error_code: "unknown",
      error_message: "No auth",
    }
  }

  const res = await fetch("/api/ai-config/test", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  const data = json?.data

  if (data && typeof data.ok === "boolean") {
    return data as AiTestResult
  }

  // El back no devolvió el shape esperado (503, 502, validación sin data).
  return {
    ok: false,
    prompt_tokens: null,
    cache_min_tokens: null,
    error_code: "unknown",
    error_message: extractError(json),
  }
}

export async function updateAiConfig(
  input: AiConfigInput,
): Promise<{ data?: AiConfig; error?: string; modelVisionWarning?: string | null }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch("/api/ai-config", {
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
  return { data: json.data, modelVisionWarning: json.model_vision_warning ?? null }
}

export function extractError(json: any): string {
  if (json?.errors && typeof json.errors === "object") {
    const first = Object.values(json.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return json?.message || "Error"
}
