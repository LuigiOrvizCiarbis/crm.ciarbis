import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

type RouteContext = { params: Promise<{ eventId: string }> }

export async function DELETE(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { eventId } = await params
  try {
    const { data, status } = await proxyToLaravel(`/api/google-calendar/events/${encodeURIComponent(eventId)}${req.nextUrl.search}`, authHeader, {
      method: "DELETE",
      timeoutMs: 60000,
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No se pudo actualizar Google Calendar. Volvé a intentar." }, { status: 503 })
  }
}
