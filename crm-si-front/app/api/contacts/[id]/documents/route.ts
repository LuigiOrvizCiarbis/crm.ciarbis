import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

type RouteContext = { params: Promise<{ id: string }> };

/**
 * Sube el PDF del contrato.
 *
 * El timeout por defecto del proxy (15s) cubre todo el forwarding del
 * multipart, no sólo la respuesta: con un PDF de varios MB por una conexión
 * lenta se corta antes de terminar de subir.
 */
export async function POST(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const { id } = await params;
  const formData = await req.formData();

  try {
    const { data, status } = await proxyToLaravel(`/api/contacts/${id}/documents`, authHeader, {
      method: "POST",
      body: formData,
      rawBody: true,
      timeoutMs: 120000,
    });
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
