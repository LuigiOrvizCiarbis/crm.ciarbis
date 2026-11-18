import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function POST(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader) return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  try {
    const body = await request.json().catch(() => ({}));
    const { data, status } = await proxyToLaravel(`/api/conversations/${id}/send-flow`, authHeader, { method: "POST", body: JSON.stringify(body) });
    return proxyResponse(data, status);
  } catch (error: any) {
    console.error("[Proxy] POST /conversations/send-flow failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}
