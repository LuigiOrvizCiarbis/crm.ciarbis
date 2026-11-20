import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function GET(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader) return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  try {
    const { data, status } = await proxyToLaravel(`/api/conversations/${id}/whatsapp-checkout`, authHeader, { method: "GET" });
    return proxyResponse(data, status);
  } catch (error: any) {
    console.error("[Proxy] GET /conversations/whatsapp-checkout failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}
