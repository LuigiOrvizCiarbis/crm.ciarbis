import { NextRequest, NextResponse } from "next/server"
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper"

type RouteContext = {
  params: Promise<{ path?: string[] }>
}

async function forward(
  request: NextRequest,
  method: "GET" | "POST" | "PATCH" | "DELETE",
  context: RouteContext,
) {
  const authorization = request.headers.get("authorization")
  if (!authorization) {
    return NextResponse.json({ message: "No auth" }, { status: 401 })
  }

  const { path = [] } = await context.params
  const suffix = path.length > 0
    ? `/${path.map(encodeURIComponent).join("/")}`
    : ""
  const body = method === "GET" || method === "DELETE"
    ? undefined
    : await request.text()

  const { data, status } = await proxyToLaravel(
    `/api/instagram-comments${suffix}${request.nextUrl.search}`,
    authorization,
    {
      method,
      body: body || undefined,
      observability: {
        feature: "instagram-comments",
        action: method.toLowerCase(),
      },
    },
  )

  return proxyResponse(data, status)
}

export function GET(request: NextRequest, context: RouteContext) {
  return forward(request, "GET", context)
}

export function POST(request: NextRequest, context: RouteContext) {
  return forward(request, "POST", context)
}

export function PATCH(request: NextRequest, context: RouteContext) {
  return forward(request, "PATCH", context)
}

export function DELETE(request: NextRequest, context: RouteContext) {
  return forward(request, "DELETE", context)
}
