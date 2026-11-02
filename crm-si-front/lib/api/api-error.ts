import { classifyApiFailure, reportApiFailure } from "@/lib/observability/sentry";

const GENERIC_ERROR = "Ocurrió un error inesperado. Intentá de nuevo más tarde.";

function reportApiError(status: number, payload: any, fallback?: string) {
  const classification = classifyApiFailure(status, payload);

  // El server/proxy ya conserva estos casos como eventos `info`; reportarlos
  // también en el cliente duplica issues sin aportar señal de incidente.
  if (classification.expected) {
    return;
  }

  reportApiFailure({
    name: "api_request_failed",
    status,
    payload,
    fallback,
    source: "client",
    feature: "api",
    extra: {
      page:
        typeof window !== "undefined"
          ? {
              path: window.location.pathname,
              search: window.location.search,
            }
          : undefined,
    },
  });
}

/**
 * Lanza un error con mensaje sanitizado según el status HTTP.
 * - 4xx: usa el mensaje del backend (errores de validación, auth, etc.)
 * - 5xx: muestra un mensaje genérico al usuario
 */
export function throwApiError(status: number, payload: any, fallback?: string): never {
  reportApiError(status, payload, fallback);

  if (status === 402 && payload?.error === "trial_expired" && typeof window !== "undefined") {
    window.location.assign("/trial-expired");
  }

  if (status >= 400 && status < 500) {
    throw new Error(payload?.message || fallback || GENERIC_ERROR);
  }

  // 5xx o cualquier otro: mensaje genérico
  throw new Error(GENERIC_ERROR);
}
