import { NextRequest } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export async function PUT(request: NextRequest) {
  const authHeader = request.headers.get("Authorization") || ""
  const { data, status } = await proxyToLaravel("/api/navigation-labels", authHeader, {
    method: "PUT",
    body: await request.text(),
  })

  return proxyResponse(data, status)
}
