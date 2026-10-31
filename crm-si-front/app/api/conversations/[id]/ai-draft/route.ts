import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

async function forward(request: NextRequest, id: string, method: "GET" | "POST" | "DELETE") {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "Authorization header required" }, { status: 401 })
  const body = method === "POST" ? JSON.stringify(await request.json()) : undefined
  try {
    const { data, status } = await proxyToLaravel(`/api/conversations/${id}/ai-draft`, auth, { method, body })
    return NextResponse.json(data, { status })
  } catch (error) {
    return NextResponse.json({ message: error instanceof Error ? error.message : "Backend connection failed" }, { status: 503 })
  }
}

export async function GET(request: NextRequest, { params }: { params: Promise<{ id: string }> }) { return forward(request, (await params).id, "GET") }
export async function POST(request: NextRequest, { params }: { params: Promise<{ id: string }> }) { return forward(request, (await params).id, "POST") }
export async function DELETE(request: NextRequest, { params }: { params: Promise<{ id: string }> }) { return forward(request, (await params).id, "DELETE") }
