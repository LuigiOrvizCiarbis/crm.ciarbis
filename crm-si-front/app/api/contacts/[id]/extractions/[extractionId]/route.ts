import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

type RouteContext = { params: Promise<{ id: string; extractionId: string }> };

/** Estado de la extracción. Es el endpoint que pollea el diálogo de revisión. */
export async function GET(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const { id, extractionId } = await params;

  try {
    const { data, status } = await proxyToLaravel(
      `/api/contacts/${id}/extractions/${extractionId}`,
      authHeader,
      { cache: "no-store" },
    );
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
