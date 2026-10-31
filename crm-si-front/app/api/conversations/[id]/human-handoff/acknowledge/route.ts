import { NextRequest, NextResponse } from "next/server";
import { proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function POST(
  request: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  const { id } = await params;
  const authHeader = request.headers.get("authorization");

  if (!authHeader) {
    return NextResponse.json(
      { error: "Authorization header required" },
      { status: 401 }
    );
  }

  try {
    const { data, status } = await proxyToLaravel(
      `/api/conversations/${id}/human-handoff/acknowledge`,
      authHeader,
      { method: "POST" }
    );
    return NextResponse.json(data, { status });
  } catch {
    return NextResponse.json(
      { error: "Backend connection failed" },
      { status: 503 }
    );
  }
}
