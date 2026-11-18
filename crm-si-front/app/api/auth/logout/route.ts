import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"
import { activeToken, readSessionJar, writeSessionJar } from "@/lib/auth-session"

/**
 * Revoca el token de la cuenta activa y la quita de la cookie. Sólo se
 * conservan las cuentas que el cliente sigue listando (`remainingUserIds`),
 * así no quedan tokens huérfanos en el navegador.
 */
export async function POST(request: NextRequest) {
  const jar = readSessionJar(request)
  const token = activeToken(jar)
  const body = await request.json().catch(() => ({}))
  const remainingUserIds: unknown[] = Array.isArray(body?.remainingUserIds) ? body.remainingUserIds : []

  let data: unknown = { message: "Sesión cerrada" }
  let status = 200
  if (token) {
    try {
      ({ data, status } = await proxyToLaravel("/api/logout", `Bearer ${token}`, { method: "POST" }))
    } catch (error) {
      // La sesión local se cierra igual aunque el backend no responda.
      console.error("[Auth Logout Error]:", error)
    }
  }

  const tokens = Object.fromEntries(
    Object.entries(jar.tokens).filter(([userId]) =>
      Number(userId) !== jar.active && remainingUserIds.includes(Number(userId)),
    ),
  )
  const response = NextResponse.json(data, { status })
  writeSessionJar(response, { active: null, tokens })
  return response
}
