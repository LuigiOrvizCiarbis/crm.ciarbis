import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function GET(req: NextRequest) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  try {
    const { data, status } = await proxyToLaravel("/api/assistant/conversations", authHeader, { cache: "no-store" });
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "Backend connection failed" }, { status: 503 });
  }
}

export async function POST(req: NextRequest) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const body = JSON.stringify(await req.json());
  try {
    const { data, status } = await proxyToLaravel("/api/assistant/conversations", authHeader, {
      method: "POST",
      body,
    });
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "Backend connection failed" }, { status: 503 });
  }
}
