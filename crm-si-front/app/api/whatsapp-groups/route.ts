import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

async function forward(request: NextRequest, method: "GET" | "POST") {
  const authHeader = request.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  try {
    const body = method === "POST" ? await request.text() : undefined
    const { data, status } = await proxyToLaravel(`/api/whatsapp-groups${request.nextUrl.search}`, authHeader, {
      method,
      body,
      headers: body ? { "Content-Type": "application/json" } : undefined,
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}

export const GET = (request: NextRequest) => forward(request, "GET")
export const POST = (request: NextRequest) => forward(request, "POST")
