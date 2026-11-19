import { NextRequest, NextResponse } from "next/server";
import { proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function POST(request: NextRequest) {
  const authHeader = request.headers.get("authorization");

  if (!authHeader) {
    return NextResponse.json(
      { error: "Authorization header required" },
      { status: 401 }
    );
  }

  const body = await request.text();

  try {
    const { data, status } = await proxyToLaravel(
      "/api/woocommerce-config/whatsapp-checkout/install",
      authHeader,
      {
        method: "POST",
        body,
        headers: { "Content-Type": "application/json" },
        cache: "no-store",
        timeoutMs: 90000,
      }
    );
    return NextResponse.json(data, { status });
  } catch {
    return NextResponse.json(
      { error: "Backend connection failed" },
      { status: 503 }
    );
  }
}
