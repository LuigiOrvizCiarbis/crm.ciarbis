import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function POST(request: NextRequest, context: { params: Promise<{ id: string }> }) {
  const authorization = request.headers.get("authorization")
  if (!authorization) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await context.params
  const { data, status } = await proxyToLaravel(`/api/contacts/import/${id}/cancel`, authorization, { method: "POST" })
  return NextResponse.json(data, { status })
}
