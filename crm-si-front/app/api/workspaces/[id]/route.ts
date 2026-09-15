import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export async function PATCH(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  const { id } = await params
  const body = await req.json()

  try {
    const { data, status } = await proxyToLaravel(`/api/workspaces/${id}`, authHeader, {
      method: "PATCH",
      body: JSON.stringify(body),
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}

export async function DELETE(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  const { id } = await params
  // El backend exige nombre y contraseña en el body para desactivar.
  const body = await req.json().catch(() => ({}))

  try {
    const { data, status } = await proxyToLaravel(`/api/workspaces/${id}`, authHeader, {
      method: "DELETE",
      body: JSON.stringify(body),
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}
