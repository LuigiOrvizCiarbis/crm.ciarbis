import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function POST(request: NextRequest) {
  const authorization = request.headers.get("authorization")
  if (!authorization) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { data, status } = await proxyToLaravel("/api/contacts/import/queue", authorization, { method: "POST", body: await request.formData(), rawBody: true })
  return NextResponse.json(data, { status })
}
