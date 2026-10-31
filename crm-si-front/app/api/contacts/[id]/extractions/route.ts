import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

type RouteContext = { params: Promise<{ id: string }> };

/** Encola la extracción. Devuelve 202: el resultado se consulta por polling. */
export async function POST(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const { id } = await params;
  const body = JSON.stringify(await req.json());

  try {
    const { data, status } = await proxyToLaravel(`/api/contacts/${id}/extractions`, authHeader, {
      method: "POST",
      body,
    });
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
