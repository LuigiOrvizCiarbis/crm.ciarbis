import { NextRequest, NextResponse } from "next/server";
import { proxyToLaravel } from "@/lib/api/proxy-helper";

export async function POST(req: NextRequest) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const body = await req.json().catch(() => null);
  if (!body) return NextResponse.json({ message: "Invalid body" }, { status: 400 });

  try {
    const { data, status } = await proxyToLaravel("/api/contacts/bulk-fields", authHeader, {
      method: "POST",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    });
    return NextResponse.json(data, { status });
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
