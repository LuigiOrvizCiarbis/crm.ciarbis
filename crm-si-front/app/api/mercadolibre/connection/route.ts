import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

async function proxy(req: NextRequest, method: string) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  const body = method === "PUT" ? JSON.stringify(await req.json().catch(() => ({}))) : undefined

  try {
    const { data, status } = await proxyToLaravel("/api/mercadolibre/connection", authHeader, {
      method,
      cache: "no-store",
      ...(body ? { body } : {}),
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}

export async function GET(req: NextRequest) {
  return proxy(req, "GET")
}

export async function PUT(req: NextRequest) {
  return proxy(req, "PUT")
}

export async function DELETE(req: NextRequest) {
  return proxy(req, "DELETE")
}
