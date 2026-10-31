import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function GET(request: NextRequest) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const result = await proxyToLaravel(`/api/invoice-recurrences${request.nextUrl.search}`, auth, { cache: "no-store" })
  return NextResponse.json(result.data, { status: result.status })
}

export async function POST(request: NextRequest) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const result = await proxyToLaravel("/api/invoice-recurrences", auth, { method: "POST", body: JSON.stringify(await request.json()) })
  return NextResponse.json(result.data, { status: result.status })
}
