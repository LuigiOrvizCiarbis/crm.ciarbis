import { NextRequest, NextResponse } from "next/server";
import { proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function PUT(
  request: NextRequest,
  { params }: { params: Promise<{ id: string; templateId: string }> },
) {
  const { id, templateId } = await params;
  const authHeader = request.headers.get("authorization") || "";

  if (!authHeader) {
    return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  }

  try {
    const body = await request.json();
    const { data, status } = await proxyToLaravel(`/api/channels/${id}/templates/${templateId}`, authHeader, {
      method: "PUT",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json", Accept: "application/json" },
    });
    return NextResponse.json(data, { status });
  } catch (error: unknown) {
    console.error("[Proxy] PUT /channels/templates failed:", error instanceof Error ? error.message : error);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}

export async function DELETE(
  request: NextRequest,
  { params }: { params: Promise<{ id: string; templateId: string }> },
) {
  const { id, templateId } = await params;
  const authHeader = request.headers.get("authorization") || "";

  if (!authHeader) {
    return NextResponse.json({ error: "Authorization header required" }, { status: 401 });
  }

  try {
    const { data, status } = await proxyToLaravel(
      `/api/channels/${id}/templates/${templateId}`,
      authHeader,
      { method: "DELETE" },
    );

    if (status === 204) return new NextResponse(null, { status });

    return NextResponse.json(data, { status });
  } catch (error: any) {
    console.error("[Proxy] DELETE /channels/templates failed:", error.message);
    return NextResponse.json({ error: "Backend connection failed" }, { status: 502 });
  }
}
