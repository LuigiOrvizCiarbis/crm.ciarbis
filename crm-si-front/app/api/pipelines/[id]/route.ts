import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

type RouteContext = { params: Promise<{ id: string }> }

async function forward(req: NextRequest, context: RouteContext, method: "PUT" | "DELETE") {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await context.params
  try {
    const { data, status } = await proxyToLaravel(`/api/pipelines/${id}`, authHeader, {
      method,
      ...(method === "PUT" ? { body: JSON.stringify(await req.json()) } : {}),
    })
    return NextResponse.json(data, { status })
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}

export async function PUT(req: NextRequest, context: RouteContext) { return forward(req, context, "PUT") }
export async function DELETE(req: NextRequest, context: RouteContext) { return forward(req, context, "DELETE") }
