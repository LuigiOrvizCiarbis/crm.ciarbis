import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

type RouteContext = {
  params: Promise<{ id: string; index: string }>;
};

export async function POST(request: NextRequest, { params }: RouteContext) {
  const authHeader = request.headers.get("authorization");

  if (!authHeader) {
    return NextResponse.json(
      { message: "Authorization header required" },
      { status: 401 },
    );
  }

  const { id, index } = await params;
  const body = await request.text();

  try {
    const { data, status } = await proxyToLaravel(
      `/api/messages/${id}/contacts/${index}/save`,
      authHeader,
      {
        method: "POST",
        body: body || undefined,
      },
    );

    return proxyResponse(data, status);
  } catch {
    return NextResponse.json(
      { message: "Backend connection failed" },
      { status: 503 },
    );
  }
}
