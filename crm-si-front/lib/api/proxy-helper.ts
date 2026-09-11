import { NextResponse } from "next/server";
import { cookies, headers } from "next/headers";
import { reportApiFailure, reportConnectionFailure } from "@/lib/observability/sentry";

type ProxyObservabilityOptions = {
  responseErrorName?: string;
  connectionErrorName?: string;
  feature?: string;
  action?: string;
  requestMeta?: Record<string, unknown>;
};

type ProxyToLaravelOptions = RequestInit & {
  rawBody?: boolean;
  baseUrls?: string[];
  observability?: ProxyObservabilityOptions;
  /**
   * Timeout por candidato en ms. Sin esto, un backend lento/inalcanzable
   * cuelga el fetch hasta el timeout TCP del SO y, al probarse varias base
   * URLs en secuencia, el request del proxy podría tardar mucho antes de
   * fallar. Default: 15s. Se ignora si el caller pasa su propio `signal`.
   */
  timeoutMs?: number;
};

function reportProxyResponseError({
  endpoint,
  method,
  status,
  base,
  payload,
  observability,
  tried,
}: {
  endpoint: string;
  method: string;
  status: number;
  base: string;
  payload: any;
  observability?: ProxyObservabilityOptions;
  tried?: string[];
}) {
  reportApiFailure({
    name: observability?.responseErrorName || "laravel_proxy_response_failed",
    status,
    payload,
    source: "proxy",
    feature: observability?.feature || "api-proxy",
    action: observability?.action,
    endpoint,
    method,
    backendBase: base,
    requestMeta: observability?.requestMeta,
    extra: tried?.length ? { triedBackends: tried } : undefined,
  });
}

function reportProxyConnectionError({
  endpoint,
  method,
  base,
  error,
  observability,
}: {
  endpoint: string;
  method: string;
  base: string;
  error: any;
  observability?: ProxyObservabilityOptions;
}) {
  reportConnectionFailure({
    name: observability?.connectionErrorName || "laravel_proxy_connection_failed",
    error,
    source: "proxy",
    feature: observability?.feature || "api-proxy",
    endpoint,
    method,
    backendBase: base,
  });
}

/**
 * Construye la respuesta del proxy a partir del resultado de proxyToLaravel.
 * Los status 204/205 no admiten body: NextResponse.json lanzaría, por lo que
 * devolvemos una respuesta vacía. Para el resto serializamos el body.
 */
export function proxyResponse(data: unknown, status: number): NextResponse {
  if (status === 204 || status === 205) {
    return new NextResponse(null, { status });
  }
  return NextResponse.json(data, { status });
}

/**
 * Helper para API routes que actúan como proxy al backend Laravel.
 * Sigue el patrón multi-URL del proyecto para funcionar en Docker y local.
 */
export async function proxyToLaravel(
  endpoint: string,
  authHeader: string,
  options: ProxyToLaravelOptions = {}
) {
  const stripSlash = (url?: string) => (url || "").replace(/\/$/, "");
  const method = (options.method || "GET").toUpperCase();
  const isRetryableMethod = method === "GET" || method === "HEAD" || method === "OPTIONS";

  const defaultBases = [
    stripSlash(process.env.API_INTERNAL_URL),       // Env: producción o local
    "http://host.docker.internal:8000",             // Docker fallback
    "http://localhost:8000",                        // Local fallback
  ].filter(Boolean);

  const bases = (options.baseUrls || defaultBases).map(stripSlash).filter(Boolean);
  const { rawBody, baseUrls, observability, timeoutMs, ...fetchOptions } = options;
  const perRequestTimeout = timeoutMs ?? 15000;
  const tried: string[] = [];
  let lastConnectionError: unknown;
  let lastConnectionBase: string | undefined;

  for (const base of bases) {
    const url = `${base}${endpoint}`;
    tried.push(url);

    try {
      const defaultHeaders: Record<string, string> = {
        "Accept": "application/json",
        "Authorization": authHeader,
      };
      // El cookie es un fallback para navegaciones. El header lo envía cada
      // pestaña desde sessionStorage y evita que un cambio en otra pestaña
      // altere el contexto de la petición actual.
      const requestHeaders = await headers();
      const workspaceId = requestHeaders.get("x-workspace-id")
        ?? (await cookies()).get("active-workspace-id")?.value;
      if (workspaceId) defaultHeaders["X-Workspace-Id"] = workspaceId;
      if (!rawBody) {
        defaultHeaders["Content-Type"] = "application/json";
      }

      const res = await fetch(url, {
        ...fetchOptions,
        // Timeout por candidato salvo que el caller ya haya provisto un signal.
        signal: fetchOptions.signal ?? AbortSignal.timeout(perRequestTimeout),
        headers: {
          ...defaultHeaders,
          ...fetchOptions.headers,
        },
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        reportProxyResponseError({
          endpoint,
          method,
          status: res.status,
          base,
          payload: data,
          observability,
          tried,
        });
      }

      // Retornar si es exitoso o error cliente (400-499)
      if (res.ok || (res.status >= 400 && res.status < 500)) {
        return { data, status: res.status };
      }

      // No reintentar mutaciones: evita duplicar escrituras cuando el backend
      // creó el recurso pero falló en un paso posterior (por ejemplo, email).
      if (!isRetryableMethod) {
        return { data, status: res.status };
      }

      // 5xx: intentar siguiente backend para métodos idempotentes
      console.warn(`[Proxy] 5xx from ${base}: ${res.status}`);
      continue;

    } catch (err: any) {
      if (!isRetryableMethod) {
        reportProxyConnectionError({
          endpoint,
          method,
          base,
          error: err,
          observability,
        });
        throw err;
      }

      lastConnectionError = err;
      lastConnectionBase = base;
      console.warn(`[Proxy] Failed ${base}:`, err.message);
      continue;
    }
  }

  if (lastConnectionError && lastConnectionBase) {
    reportProxyConnectionError({
      endpoint,
      method,
      base: lastConnectionBase,
      error: lastConnectionError,
      observability,
    });
  }

  // Todos los backends fallaron o devolvieron 5xx: mensaje genérico
  return {
    data: { message: "Ocurrió un error interno. Inténtalo de nuevo más tarde." },
    status: 502,
  };
}
