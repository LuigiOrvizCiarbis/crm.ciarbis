import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function GET(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const authorization = request.headers.get("authorization")
  if (!authorization) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await params
  const { data, status, contentType, contentDisposition } = await proxyToLaravel(
    "/api/products/import/" + id + "/errors",
    authorization,
    { responseType: "text", headers: { Accept: "text/csv" } },
  )
  return new NextResponse(data, {
    status,
    headers: {
      "Content-Type": contentType || "text/csv; charset=UTF-8",
      ...(contentDisposition ? { "Content-Disposition": contentDisposition } : {}),
    },
  })
}
