import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

type RouteContext = { params: Promise<{ categoryId: string }> }

export async function GET(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  const { categoryId } = await params

  try {
    const { data, status } = await proxyToLaravel(
      `/api/mercadolibre/categories/${encodeURIComponent(categoryId)}/attributes`,
      authHeader,
    )
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}
