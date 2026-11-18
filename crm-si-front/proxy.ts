import { NextResponse, type NextRequest } from "next/server"
import { activeToken, readSessionJar } from "@/lib/auth-session"

const SAFE_METHODS: Record<string, true> = { GET: true, HEAD: true, OPTIONS: true }

/**
 * Única fuente de autenticación de /api/*: el token de la cuenta activa sale de
 * la cookie httpOnly; cualquier Authorization enviado por el cliente se descarta.
 * Como la cookie viaja sola, las peticiones originadas en otro sitio se rechazan
 * (mutaciones) o siguen sin credenciales (lecturas): protección CSRF.
 */
export function proxy(request: NextRequest) {
  const fetchSite = request.headers.get("sec-fetch-site")
  const isForeignOrigin = fetchSite === "cross-site" || fetchSite === "same-site"
  if (isForeignOrigin && !SAFE_METHODS[request.method]) {
    return NextResponse.json({ message: "Origen no permitido" }, { status: 403 })
  }

  const headers = new Headers(request.headers)
  headers.delete("authorization")
  const token = isForeignOrigin ? null : activeToken(readSessionJar(request))
  if (token) headers.set("authorization", `Bearer ${token}`)

  return NextResponse.next({ request: { headers } })
}

export const config = {
  matcher: "/api/:path*",
}
