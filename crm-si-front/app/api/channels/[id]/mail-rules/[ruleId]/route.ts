import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function DELETE(request: NextRequest, context: { params: Promise<{ id: string; ruleId: string }> }) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id, ruleId } = await context.params
  const { data, status } = await proxyToLaravel(`/api/channels/${encodeURIComponent(id)}/mail-rules/${encodeURIComponent(ruleId)}`, auth, { method: "DELETE" })
  return NextResponse.json(data, { status })
}
