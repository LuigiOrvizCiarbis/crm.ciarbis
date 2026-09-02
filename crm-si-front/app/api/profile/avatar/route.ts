import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

export const dynamic = "force-dynamic"

export async function POST(request: NextRequest) {
  const authHeader = request.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  const formData = await request.formData()

  try {
    const { data, status } = await proxyToLaravel("/api/profile/avatar", authHeader, {
      method: "POST",
      body: formData,
      rawBody: true,
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}

export async function DELETE(request: NextRequest) {
  const authHeader = request.headers.get("authorization")
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 })

  try {
    const { data, status } = await proxyToLaravel("/api/profile/avatar", authHeader, {
      method: "DELETE",
    })
    return proxyResponse(data, status)
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 })
  }
}
