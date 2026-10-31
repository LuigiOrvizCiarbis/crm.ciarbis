import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

async function forward(request: NextRequest, method: string, context: { params: Promise<{ path: string[] }> }) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { path } = await context.params
  const body = method === "GET" ? undefined : await request.text()
  const { data, status } = await proxyToLaravel(`/api/mail-intakes/${path.map(encodeURIComponent).join("/")}${request.nextUrl.search}`, auth, {
    method,
    body: body || undefined,
    headers: body ? { "Content-Type": request.headers.get("content-type") || "application/json" } : undefined,
  })
  return NextResponse.json(data, { status })
}

export const GET = (request: NextRequest, context: { params: Promise<{ path: string[] }> }) => forward(request, "GET", context)
export const POST = (request: NextRequest, context: { params: Promise<{ path: string[] }> }) => forward(request, "POST", context)
