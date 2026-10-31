import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export async function PUT(request: NextRequest) {
  const authHeader = request.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  try {
    const { data, status } = await proxyToLaravel("/api/profile/password", authHeader, {
      method: "PUT",
      body: await request.text(),
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}
