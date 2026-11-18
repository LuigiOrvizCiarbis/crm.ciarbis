import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function POST(request: NextRequest, { params }: { params: Promise<{ flowId: string }> }) {
  const { flowId } = await params;
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader) return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  try {
    const { data, status } = await proxyToLaravel(`/api/whatsapp-flows/${flowId}/publish`, authHeader, { method: "POST", timeoutMs: 60000 });
    return proxyResponse(data, status);
  } catch (error: any) {
    console.error("[Proxy] POST /whatsapp-flows/publish failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}
