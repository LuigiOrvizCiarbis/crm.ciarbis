import { getAuthToken, workspaceHeaders } from "./auth-token";
import { throwApiError } from "./api-error";

export interface BillingConfig {
  enabled: boolean;
  due_date_field_key: string | null;
  status_field_key: string | null;
  overdue_cycles_field_key: string | null;
  externally_managed_field_key: string | null;
  cycle_unit: string;
  cycle_length: number;
  timezone: string | null;
  grace_days: number;
  last_rolled_at: string | null;
}

/** Estado de una plantilla ya pedida a Meta. Ausente si todavía no se pidió. */
export interface BillingTemplateState {
  id: number;
  status: string;
  status_label: string;
  rejected_reason: string | null;
}

/**
 * Borrador de una plantilla de cobranza: el texto sugerido que el usuario
 * puede editar antes de mandarlo a Meta. `components` va tal cual al endpoint
 * de creación de plantillas.
 */
export interface BillingTemplateDraft {
  key: "reminder" | "overdue" | "trial";
  label: string;
  description: string;
  name: string;
  language: string;
  category: "UTILITY";
  parameter_format: "named";
  components: Array<{
    type: string;
    text?: string;
    example?: { body_text_named_params?: Array<{ param_name: string; example: string }> };
  }>;
  template: BillingTemplateState | null;
}

export async function getBillingConfig(): Promise<BillingConfig> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const res = await fetch("/api/billing-config", {
    headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() },
    cache: "no-store",
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al cargar la configuración de cobranzas");
  return data.data ?? data;
}

export async function getBillingTemplateDrafts(): Promise<BillingTemplateDraft[]> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const res = await fetch("/api/billing-config/template-drafts", {
    headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() },
    cache: "no-store",
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al cargar las plantillas de cobranza");
  return data.data ?? data;
}

/** Reemplaza el texto del BODY conservando el resto de los componentes. */
export function withBodyText(
  draft: BillingTemplateDraft,
  text: string,
): BillingTemplateDraft["components"] {
  return draft.components.map((component) =>
    component.type === "BODY" ? { ...component, text } : component,
  );
}

/** Texto actual del BODY de un borrador. */
export function bodyTextOf(draft: BillingTemplateDraft): string {
  return draft.components.find((component) => component.type === "BODY")?.text ?? "";
}

/** Nombres de las variables que usa el body, en orden de aparición. */
export function variablesOf(bodyText: string): string[] {
  const matches = bodyText.matchAll(/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/g);
  return Array.from(new Set(Array.from(matches, (m) => m[1])));
}
