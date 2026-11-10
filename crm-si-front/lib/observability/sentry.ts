import * as Sentry from "@sentry/nextjs";

type ApiPayload = {
  message?: unknown;
  error?: unknown;
  code?: unknown;
};

type ReportApiFailureInput = {
  name: string;
  status: number;
  payload: ApiPayload;
  fallback?: string;
  source: "client" | "proxy" | "server-route";
  feature: string;
  action?: string;
  endpoint?: string;
  method?: string;
  backendBase?: string;
  requestMeta?: Record<string, unknown>;
  extra?: Record<string, unknown>;
};

const EXPECTED_BUSINESS_PATTERNS = [
  /whatsapp.*expir/i,
  /conexi[oó]n de whatsapp expir/i,
  /reconect[aá].*canal/i,
  /canal.*desconect/i,
  /channel.*disconnect/i,
  /token.*expir/i,
  /unauthenticated/i,
  /unauthorized/i,
  /validation/i,
  /validaci[oó]n/i,
  /trial_expired/i,
  /ventana de 24 horas/i,
  /plantilla aprobada/i,
];

export function isExpectedBusinessErrorMessage(message: unknown) {
  if (typeof message !== "string") {
    return false;
  }

  return EXPECTED_BUSINESS_PATTERNS.some((pattern) => pattern.test(message));
}

export function sanitizeApiPayload(payload: ApiPayload) {
  return {
    message: typeof payload?.message === "string" ? payload.message : undefined,
    error: typeof payload?.error === "string" ? payload.error : undefined,
    code: typeof payload?.code === "string" || typeof payload?.code === "number" ? payload.code : undefined,
  };
}

export function isExpectedBusinessError(status: number, payload: ApiPayload) {
  if (status === 401 || status === 403 || status === 422) {
    return true;
  }

  if (status < 400 || status >= 500) {
    return false;
  }

  const sanitized = sanitizeApiPayload(payload);
  const haystack = [sanitized.message, sanitized.error, sanitized.code]
    .filter((value) => value != null)
    .join(" ");

  return isExpectedBusinessErrorMessage(haystack);
}

export function classifyApiFailure(status: number, payload: ApiPayload) {
  const expected = isExpectedBusinessError(status, payload);

  if (expected) {
    return {
      level: "info" as const,
      expected: true,
      businessError: true,
      alertable: false,
    };
  }

  if (status >= 500) {
    return {
      level: "error" as const,
      expected: false,
      businessError: false,
      alertable: true,
    };
  }

  return {
    level: "warning" as const,
    expected: false,
    businessError: false,
    alertable: false,
  };
}

export function reportApiFailure({
  name,
  status,
  payload,
  fallback,
  source,
  feature,
  action,
  endpoint,
  method,
  backendBase,
  requestMeta,
  extra,
}: ReportApiFailureInput) {
  const classification = classifyApiFailure(status, payload);
  const sanitizedPayload = sanitizeApiPayload(payload);
  const tags: Record<string, string> = {
    source,
    feature,
    http_status: String(status),
    backend_status: String(status),
    expected: String(classification.expected),
    business_error: String(classification.businessError),
    alertable: String(classification.alertable),
  };

  if (action) tags.action = action;
  if (endpoint) tags.endpoint = endpoint;
  if (method) tags.method = method;

  // Título descriptivo para que el issue en Sentry sea identificable sin
  // abrirlo. El fingerprint sigue usando `name`, así que el agrupado no cambia.
  const location = [method, endpoint].filter(Boolean).join(" ");
  const title = location ? `${name}: ${location} -> ${status}` : name;

  Sentry.captureMessage(title, {
    level: classification.level,
    tags,
    fingerprint: [
      name,
      feature,
      endpoint || "unknown-endpoint",
      String(status),
      classification.businessError ? String(sanitizedPayload.code || sanitizedPayload.message || "business-error") : "technical",
    ],
    extra: {
      fallback,
      backendBase,
      apiError: sanitizedPayload,
      request: requestMeta,
      ...extra,
    },
  });

  return classification;
}

export function reportConnectionFailure({
  name,
  error,
  source,
  feature,
  endpoint,
  method,
  backendBase,
}: {
  name: string;
  error: unknown;
  source: "proxy" | "server-route";
  feature: string;
  endpoint: string;
  method: string;
  backendBase?: string;
}) {
  // Envolvemos el error crudo ("fetch failed", "ETIMEDOUT"...) con contexto
  // del request para que el título del issue en Sentry sea identificable.
  // El error original queda en `cause` y Sentry lo muestra enlazado.
  const location = [method, endpoint].filter(Boolean).join(" ");
  const wrapped = new Error(`${name}: ${location}${backendBase ? ` (${backendBase})` : ""}`);
  (wrapped as Error & { cause?: unknown }).cause = error;

  Sentry.captureException(wrapped, {
    tags: {
      source,
      feature,
      endpoint,
      method,
      expected: "false",
      business_error: "false",
      alertable: "true",
    },
    fingerprint: [name, feature, endpoint, method, "connection-failure"],
    extra: {
      backendBase,
      errorCode: typeof error === "object" && error !== null && "code" in error ? error.code : undefined,
    },
  });
}
