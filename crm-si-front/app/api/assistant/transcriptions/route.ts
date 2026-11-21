import { NextRequest, NextResponse } from "next/server";
import { proxyResponse, proxyToLaravel } from "@/lib/api/proxy-helper";

export const dynamic = "force-dynamic";

/**
 * Dictado por voz del asistente: reenvía el audio grabado a Laravel, que lo
 * transcribe. El timeout cubre la subida del multipart y la transcripción.
 */
export async function POST(req: NextRequest) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const formData = await req.formData();

  try {
    const { data, status } = await proxyToLaravel("/api/assistant/transcriptions", authHeader, {
      method: "POST",
      body: formData,
      rawBody: true,
      timeoutMs: 90000,
    });
    return proxyResponse(data, status);
  } catch {
    return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
  }
}
