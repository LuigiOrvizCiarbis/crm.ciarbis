import { NextRequest, NextResponse } from "next/server";

export const dynamic = "force-dynamic";

type RouteContext = { params: Promise<{ id: string }> };

const stripSlash = (url?: string) => (url || "").replace(/\/$/, "");

/**
 * Descarga del adjunto (documento/video) de un mensaje de chat.
 *
 * No usa proxyToLaravel: ese helper hace res.json() sobre la respuesta, lo que
 * destruye un binario. Acá se reenvía el body como stream, sin materializar el
 * archivo en memoria del servidor de Next. Mismo patrón que
 * app/api/media-assets/[id]/download/route.ts.
 */
export async function GET(req: NextRequest, { params }: RouteContext) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const { id } = await params;
  const download = req.nextUrl.searchParams.get("download") === "1" ? "?download=1" : "";

  const bases = [
    stripSlash(process.env.API_INTERNAL_URL),
    "http://host.docker.internal:8000",
    "http://localhost:8000",
  ].filter(Boolean);

  for (const base of bases) {
    try {
      const res = await fetch(`${base}/api/messages/${id}/media${download}`, {
        headers: { Authorization: authHeader },
        // Un video de varias decenas de MB por una conexión lenta no entra en
        // el timeout de 15s que usa el proxy genérico.
        signal: AbortSignal.timeout(120000),
      });

      if (!res.ok) {
        // El front distingue 404 (archivo borrado) de 403 (sin acceso), así que
        // el status se conserva en vez de colapsarlo en un error genérico.
        return NextResponse.json(
          await res.json().catch(() => ({ message: "No se pudo abrir el archivo." })),
          { status: res.status },
        );
      }

      const headers = new Headers();
      for (const key of ["content-type", "content-length", "content-disposition"]) {
        const value = res.headers.get(key);
        if (value) headers.set(key, value);
      }
      headers.set("Cache-Control", "private, no-store, max-age=0");
      headers.set("X-Content-Type-Options", "nosniff");

      return new NextResponse(res.body, { status: 200, headers });
    } catch {
      // Backend inalcanzable: se prueba el siguiente candidato.
      continue;
    }
  }

  return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
}
