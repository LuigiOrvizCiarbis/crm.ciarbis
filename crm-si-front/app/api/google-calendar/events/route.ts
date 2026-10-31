import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  const authHeader = req.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })
  try {
    const { data, status } = await proxyToLaravel(`/api/google-calendar/events${req.nextUrl.search}`, authHeader, {
      cache: "no-store",
      timeoutMs: 60000,
    })
    // GET may fall through proxy bases; keep the Google-specific message instead of a generic 502.
    if (status >= 500) return NextResponse.json({ message: "No se pudo consultar Google Calendar. Volvé a intentar." }, { status: 503 })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No se pudo consultar Google Calendar. Volvé a intentar." }, { status: 503 })
  }
}
