import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

async function forward(request: NextRequest, method: string, context: { params: Promise<{ id: string }> }) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await context.params
  const { data, status } = await proxyToLaravel(`/api/channels/${encodeURIComponent(id)}/mail-rules`, auth, { method, body: method === "POST" ? await request.text() : undefined })
  return NextResponse.json(data, { status })
}
export const GET = (request: NextRequest, context: { params: Promise<{ id: string }> }) => forward(request, "GET", context)
export const POST = (request: NextRequest, context: { params: Promise<{ id: string }> }) => forward(request, "POST", context)
