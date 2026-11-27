import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

type RouteContext = { params: Promise<{ id: string }> }

async function proxy(req: NextRequest, { params }: RouteContext, method: string) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  const { id } = await params
  const body = method === "PUT" ? JSON.stringify(await req.json().catch(() => ({}))) : undefined

  try {
    const { data, status } = await proxyToLaravel(`/api/products/${id}/mercadolibre`, authHeader, {
      method,
      cache: "no-store",
      ...(body ? { body } : {}),
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}

export async function GET(req: NextRequest, ctx: RouteContext) {
  return proxy(req, ctx, "GET")
}

export async function PUT(req: NextRequest, ctx: RouteContext) {
  return proxy(req, ctx, "PUT")
}
