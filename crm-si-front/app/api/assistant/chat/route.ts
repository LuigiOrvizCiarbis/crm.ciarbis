import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";

export const dynamic = "force-dynamic";

const stripSlash = (url?: string) => (url || "").replace(/\/$/, "");

const FORWARDED_HEADERS = ["content-type", "x-vercel-ai-ui-message-stream"];

/**
 * Turno del asistente interno en streaming (protocolo de useChat).
 *
 * No usa proxyToLaravel: ese helper hace res.json() y materializaría la
 * respuesta entera. Acá el body se reenvía como stream, igual que
 * app/api/messages/[id]/media/route.ts. `Accept: text/event-stream` hace que
 * Apache no comprima ni bufferee la respuesta (docker/apache/common.conf).
 */
export async function POST(req: NextRequest) {
  const authHeader = req.headers.get("authorization");
  if (!authHeader) return NextResponse.json({ message: "No auth" }, { status: 401 });

  const body = await req.text();
  const workspaceId = req.headers.get("x-workspace-id") ?? (await cookies()).get("active-workspace-id")?.value;

  const bases = [
    stripSlash(process.env.API_INTERNAL_URL),
    "http://host.docker.internal:8000",
    "http://localhost:8000",
  ].filter(Boolean);

  for (const base of bases) {
    let res: Response;
    try {
      res = await fetch(`${base}/api/assistant/chat`, {
        method: "POST",
        body,
        headers: {
          Authorization: authHeader,
          "Content-Type": "application/json",
          Accept: "text/event-stream",
          ...(workspaceId ? { "X-Workspace-Id": workspaceId } : {}),
        },
        // "Detener" en el panel corta también la generación en el backend.
        signal: req.signal,
      });
    } catch (error) {
      if (req.signal.aborted) return new Response(null, { status: 499 });
      // Solo se prueba otro backend si este nunca recibió el pedido: reenviar
      // un turno que ya llegó lo cobraría dos veces.
      if (isUnreachable(error)) continue;
      return NextResponse.json({ message: "No se pudo enviar el mensaje." }, { status: 502 });
    }

    if (res.status === 429) {
      // El texto de Laravel ("Too Many Attempts.") está en inglés: el panel
      // traduce por código.
      const retryAfter = res.headers.get("retry-after");
      return NextResponse.json(
        { message: "Too Many Attempts.", code: "rate_limited" },
        { status: 429, headers: retryAfter ? { "Retry-After": retryAfter } : undefined },
      );
    }

    if (!res.ok) {
      return NextResponse.json(
        await res.json().catch(() => ({ message: "No se pudo enviar el mensaje." })),
        { status: res.status },
      );
    }

    const headers = new Headers({ "Cache-Control": "no-cache, no-transform" });
    for (const key of FORWARDED_HEADERS) {
      const value = res.headers.get(key);
      if (value) headers.set(key, value);
    }

    return new Response(res.body, { status: res.status, headers });
  }

  return NextResponse.json({ message: "No reachable backend" }, { status: 503 });
}

const UNREACHABLE_CODES: Record<string, true> = {
  ECONNREFUSED: true,
  ENOTFOUND: true,
  EAI_AGAIN: true,
  EHOSTUNREACH: true,
};

/** fetch de Node envuelve el error de red en `cause` (TypeError: fetch failed). */
function isUnreachable(error: unknown): boolean {
  if (!(error instanceof Error) || !error.cause || typeof error.cause !== "object" || !("code" in error.cause)) return false;
  const { code } = error.cause;
  return typeof code === "string" && UNREACHABLE_CODES[code] === true;
}
