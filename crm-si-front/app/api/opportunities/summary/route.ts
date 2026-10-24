import { NextRequest, NextResponse } from "next/server";
import { proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

export async function GET(req: NextRequest) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) {
    return NextResponse.json({ message: "No auth" }, { status: 401 });
  }

  try {
    const { data, status } = await proxyToLaravel(`/api/opportunities/summary${req.nextUrl.search}`, authHeader);
    return NextResponse.json(data, { status });
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
