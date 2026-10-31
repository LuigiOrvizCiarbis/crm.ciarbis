import { NextRequest } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export async function GET(request: NextRequest, { params }: { params: Promise<{ id: string; recipientId: string }> }) {
  const auth = request.headers.get("authorization") ?? ""
  const { id, recipientId } = await params
  const { data, status } = await proxyToLaravel(`/api/broadcasts/${id}/recipients/${recipientId}`, auth, { method: "GET" })
  return proxyResponse(data, status)
}
