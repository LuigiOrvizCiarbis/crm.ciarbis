import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function GET(request: NextRequest) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { data, status } = await proxyToLaravel(`/api/mail-intakes${request.nextUrl.search}`, auth)
  return NextResponse.json(data, { status })
}
