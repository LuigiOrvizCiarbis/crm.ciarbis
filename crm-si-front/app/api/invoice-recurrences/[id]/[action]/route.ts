import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function POST(request: NextRequest, { params }: { params: Promise<{ id: string; action: string }> }) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id, action } = await params
  const result = await proxyToLaravel(`/api/invoice-recurrences/${id}/${action}`, auth, { method: "POST", body: "{}" })
  return NextResponse.json(result.data, { status: result.status })
}
