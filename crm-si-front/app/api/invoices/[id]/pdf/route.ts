import { NextRequest, NextResponse } from "next/server"
import { proxyToLaravel } from "@/lib/api/proxy-helper"

export async function GET(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const auth = request.headers.get("authorization")
  if (!auth) return NextResponse.json({ message: "No auth" }, { status: 401 })
  const { id } = await params
  const result = await proxyToLaravel(`/api/invoices/${id}/pdf`, auth, { responseType: "arrayBuffer", cache: "no-store" })
  if (result.status < 200 || result.status >= 300) return NextResponse.json({ message: "No se pudo descargar el PDF" }, { status: result.status })
  return new Response(result.data as ArrayBuffer, { status: result.status, headers: { "Content-Type": result.contentType ?? "application/pdf", "Content-Disposition": result.contentDisposition ?? `attachment; filename="invoice-${id}.pdf"` } })
}
