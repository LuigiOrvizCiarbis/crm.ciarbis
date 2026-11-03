import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

async function proxy(req: NextRequest, method: "GET" | "PUT") {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  try {
    const { data, status } = await proxyToLaravel("/api/dashboard/goals", authHeader, {
      method,
      cache: "no-store",
      ...(method === "PUT" ? { body: JSON.stringify(await req.json()) } : {}),
    });
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}

export function GET(req: NextRequest) {
  return proxy(req, "GET");
}

export function PUT(req: NextRequest) {
  return proxy(req, "PUT");
}
