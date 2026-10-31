import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function PUT(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await params
  const result = await proxyToLaravel(`/api/invoice-recurrences/${id}`, auth, { method: "PUT", body: JSON.stringify(await request.json()) })
  return NextResponse.json(result.data, { status: result.status })
}
