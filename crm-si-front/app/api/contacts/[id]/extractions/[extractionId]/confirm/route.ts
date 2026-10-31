import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

type RouteContext = { params: Promise<{ id: string; extractionId: string }> };

/**
 * Aplica los campos confirmados al contacto.
 *
 * Un 409 del backend significa que el contacto cambió desde que arrancó la
 * extracción; se propaga tal cual para que la UI ofrezca recargar en vez de
 * pisar la edición ajena.
 */
export async function POST(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const { id, extractionId } = await params;
  const body = JSON.stringify(await req.json());

  try {
    const { data, status } = await proxyToLaravel(
      `/api/contacts/${id}/extractions/${extractionId}/confirm`,
      authHeader,
      { method: "POST", body },
    );
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
