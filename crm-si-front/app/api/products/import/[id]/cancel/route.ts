import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function POST(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const authorization = request.headers.get("authorization")
  if (!authorization) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await params
  const { data, status } = await proxyToLaravel("/api/products/import/" + id + "/cancel", authorization, { method: "POST" })
  return NextResponse.json(data, { status })
}
