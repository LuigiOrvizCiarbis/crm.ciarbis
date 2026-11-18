import { NextResponse, type NextRequest } from "next/server"

/**
 * Los tokens de Sanctum viven sólo en esta cookie httpOnly: ningún script del
 * navegador puede leerlos. Guarda el token de cada cuenta agregada en este
 * navegador y cuál está activa; `proxy.ts` inyecta el de la activa en /api/*.
 */
const AUTH_COOKIE = "crm-auth"
// Tope de vida que aceptan los navegadores; los tokens de Sanctum no vencen.
const AUTH_COOKIE_MAX_AGE = 60 * 60 * 24 * 400

export interface SessionJar {
  active: number | null
  tokens: Record<string, string>
}

export function readSessionJar(request: NextRequest): SessionJar {
  const raw = request.cookies.get(AUTH_COOKIE)?.value
  if (!raw) return { active: null, tokens: {} }
  try {
    const parsed = JSON.parse(Buffer.from(raw, "base64url").toString("utf8"))
    const tokens = Object.fromEntries(
      Object.entries(parsed?.tokens ?? {}).filter((entry): entry is [string, string] => typeof entry[1] === "string"),
    )
    const active = Number.isInteger(parsed?.active) && tokens[parsed.active] ? parsed.active : null
    return { active, tokens }
  } catch {
    return { active: null, tokens: {} }
  }
}

export function activeToken(jar: SessionJar): string | null {
  return jar.active === null ? null : jar.tokens[jar.active] ?? null
}

export function writeSessionJar(response: NextResponse, jar: SessionJar): void {
  if (Object.keys(jar.tokens).length === 0) {
    response.cookies.delete(AUTH_COOKIE)
    return
  }
  response.cookies.set(AUTH_COOKIE, Buffer.from(JSON.stringify(jar)).toString("base64url"), {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: AUTH_COOKIE_MAX_AGE,
  })
}

/**
 * Respuesta de login/registro: el token emitido entra en la cookie como cuenta
 * activa y se quita del body para que nunca llegue al JavaScript del cliente.
 */
export function respondWithIssuedToken(request: NextRequest, data: unknown, status: number): NextResponse {
  if (status >= 400 || !isIssuedSession(data)) {
    return NextResponse.json(data, { status })
  }

  const { token, ...payload } = data
  const response = NextResponse.json(payload, { status })
  const jar = readSessionJar(request)
  writeSessionJar(response, { active: data.user.id, tokens: { ...jar.tokens, [data.user.id]: token } })
  return response
}

function isIssuedSession(data: unknown): data is { token: string; user: { id: number } } {
  if (!data || typeof data !== "object" || !("token" in data) || !("user" in data)) return false
  const { token, user } = data
  return typeof token === "string"
    && !!user && typeof user === "object" && "id" in user && Number.isInteger(user.id)
}
