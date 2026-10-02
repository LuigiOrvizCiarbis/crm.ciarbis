import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

type Context = { params: Promise<{ path: string[] }> }

async function forward(request: NextRequest, context: Context) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { path } = await context.params
  const body = ["GET", "HEAD"].includes(request.method) ? undefined : JSON.stringify(await request.json())
  const result = await proxyToLaravel(`/api/invoices/${path.join("/")}${request.nextUrl.search}`, auth, { method: request.method, body, cache: "no-store" })
  return NextResponse.json(result.data, { status: result.status })
}

export const GET = forward
export const POST = forward
export const PUT = forward
