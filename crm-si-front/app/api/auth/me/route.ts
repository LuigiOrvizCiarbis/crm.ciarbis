import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function GET(request: NextRequest) {
  try {
    const authHeader = request.headers.get("Authorization")

    if (!authHeader) {
      return NextResponse.json(
        { authenticated: false, message: "No autorizado" },
        { status: 401 }
      )
    }

    const { data, status } = await proxyToLaravel("/api/user", authHeader, {
      method: "GET",
    })

    if (status === 200) {
      const user = data?.user ?? data
      const role = data?.role ?? null
      const permissions = data?.permissions ?? []
      return NextResponse.json({ authenticated: true, user, role, permissions, workspaces: data?.workspaces ?? [] })
    }

    return NextResponse.json(
      { authenticated: false, message: "Sesión inválida" },
      { status: 401 }
    )
  } catch (error: any) {
    console.error("[Auth Me Error]:", error)
    return NextResponse.json(
      { authenticated: false, message: "Error al conectar con el servidor" },
      { status: 500 }
    )
  }
}
