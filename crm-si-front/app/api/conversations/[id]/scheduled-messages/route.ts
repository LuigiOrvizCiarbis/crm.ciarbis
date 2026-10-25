import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

type RouteContext = { params: Promise<{ id: string }> };

export async function GET(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) {
    return NextResponse.json({ message: "No authorization token provided" }, { status: 401 });
  }

  const { id } = await params;
  const { data, status } = await proxyToLaravel(`/api/conversations/${id}/scheduled-messages`, authHeader, {
    method: "GET",
    observability: { feature: "chats", action: "list_scheduled_messages" },
  });

  return proxyResponse(data, status);
}

export async function POST(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) {
    return NextResponse.json({ message: "No authorization token provided" }, { status: 401 });
  }

  const { id } = await params;
  const isMultipart = (req.headers.get("content-type") || "").includes("multipart/form-data");

  // El body se lee una sola vez antes del loop de reintentos del helper.
  const { data, status } = await proxyToLaravel(`/api/conversations/${id}/scheduled-messages`, authHeader, {
    method: "POST",
    body: isMultipart ? await req.formData() : JSON.stringify(await req.json()),
    rawBody: isMultipart,
    observability: { feature: "chats", action: "schedule_message", requestMeta: { conversationId: id } },
  });

  return proxyResponse(data, status);
}
