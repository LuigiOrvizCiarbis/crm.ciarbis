import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function GET(request: NextRequest, { params }: { params: Promise<{ flowId: string }> }) {
  const { flowId } = await params;
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader) return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  try {
    const { data, status } = await proxyToLaravel(`/api/whatsapp-flows/${flowId}`, authHeader);
    return proxyResponse(data, status);
  } catch (error: any) {
    console.error("[Proxy] GET /whatsapp-flows failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}

export async function PUT(request: NextRequest, { params }: { params: Promise<{ flowId: string }> }) {
  const { flowId } = await params;
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader) return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  try {
    const body = await request.json().catch(() => ({}));
    const { data, status } = await proxyToLaravel(`/api/whatsapp-flows/${flowId}`, authHeader, { method: "PUT", timeoutMs: 60000, body: JSON.stringify(body) });
    return proxyResponse(data, status);
  } catch (error: any) {
    console.error("[Proxy] PUT /whatsapp-flows failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}

export async function DELETE(request: NextRequest, { params }: { params: Promise<{ flowId: string }> }) {
  const { flowId } = await params;
  const authHeader = request.headers.get("authorization") || "";
  if (!authHeader) return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  try {
    const { data, status } = await proxyToLaravel(`/api/whatsapp-flows/${flowId}`, authHeader, { method: "DELETE" });
    return proxyResponse(data, status);
  } catch (error: any) {
    console.error("[Proxy] DELETE /whatsapp-flows failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}
