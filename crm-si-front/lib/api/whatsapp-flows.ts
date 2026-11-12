import type {
  Message,
  WhatsAppFlow,
  WhatsAppFlowCategory,
  WhatsAppFlowEncryptionStatus,
  WhatsAppFlowFieldMapping,
} from "@/data/types";
import type { FlowDefinition } from "@/lib/flow-builder/types";
import { workspaceHeaders } from "./auth-token";
import { throwApiError } from "./api-error";

/**
 * 422 de Laravel con `errors` por campo. Lo lanzan `createFlow` y `updateFlow`
 * para que el constructor marque cada bloque con su error.
 */
export class ApiValidationError extends Error {
  errors: Record<string, string[]>;

  constructor(message: string, errors: Record<string, string[]>) {
    super(message);
    this.name = "ApiValidationError";
    this.errors = errors;
  }
}

export interface SendFlowInput {
  whatsapp_flow_id: number;
  body: string;
  cta: string;
  header?: string;
  footer?: string;
}

export type CreateFlowPayload = { name: string; categories: WhatsAppFlowCategory[] } & (
  | { flow_json: string }
  | { builder_definition: FlowDefinition }
);

export type UpdateFlowPatch = Partial<{
  name: string;
  categories: WhatsAppFlowCategory[];
  flow_json: string;
  builder_definition: FlowDefinition;
  field_mappings: WhatsAppFlowFieldMapping[];
}>;

function jsonHeaders(): HeadersInit {
  return {
    Accept: "application/json",
    "Content-Type": "application/json",
    ...workspaceHeaders(),
  };
}

async function request(url: string, init: RequestInit, fallback: string): Promise<any> {
  const res = await fetch(url, { ...init, headers: jsonHeaders() });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, fallback);
  return data;
}

/** Igual que `request`, pero conserva los errores por campo de un 422. */
async function requestWithValidation(url: string, init: RequestInit, fallback: string): Promise<any> {
  const res = await fetch(url, { ...init, headers: jsonHeaders() });
  const data = await res.json().catch(() => ({}));
  if (res.status === 422 && data && typeof data.errors === "object" && data.errors !== null) {
    throw new ApiValidationError(data.message || fallback, data.errors as Record<string, string[]>);
  }
  if (!res.ok) throwApiError(res.status, data, fallback);
  return data;
}

export async function getFlows(channelId: number, opts?: { sendable?: boolean }): Promise<WhatsAppFlow[]> {
  const query = opts?.sendable ? "?sendable=1" : "";
  const data = await request(`/api/channels/${channelId}/flows${query}`, { method: "GET" }, "Error al cargar formularios");
  return Array.isArray(data) ? data : data?.data ?? [];
}

export async function syncFlows(channelId: number): Promise<{ count: number }> {
  const data = await request(`/api/channels/${channelId}/flows/sync`, { method: "POST" }, "Error al sincronizar formularios");
  return { count: Number(data?.count ?? 0) };
}

export async function createFlow(channelId: number, payload: CreateFlowPayload): Promise<WhatsAppFlow> {
  const data = await requestWithValidation(
    `/api/channels/${channelId}/flows`,
    { method: "POST", body: JSON.stringify(payload) },
    "Error al crear el formulario",
  );
  return data.data;
}

export async function getFlow(id: number): Promise<WhatsAppFlow> {
  const data = await request(`/api/whatsapp-flows/${id}`, { method: "GET" }, "Error al cargar el formulario");
  return data.data;
}

export async function updateFlow(id: number, patch: UpdateFlowPatch): Promise<WhatsAppFlow> {
  const data = await requestWithValidation(
    `/api/whatsapp-flows/${id}`,
    { method: "PUT", body: JSON.stringify(patch) },
    "Error al guardar el formulario",
  );
  return data.data;
}

export async function publishFlow(id: number): Promise<WhatsAppFlow> {
  const data = await request(`/api/whatsapp-flows/${id}/publish`, { method: "POST" }, "Error al publicar el formulario");
  return data.data;
}

export async function deprecateFlow(id: number): Promise<WhatsAppFlow> {
  const data = await request(`/api/whatsapp-flows/${id}/deprecate`, { method: "POST" }, "Error al deprecar el formulario");
  return data.data;
}

export async function cloneFlow(id: number, name: string): Promise<WhatsAppFlow> {
  const data = await request(
    `/api/whatsapp-flows/${id}/clone`,
    { method: "POST", body: JSON.stringify({ name }) },
    "Error al duplicar el formulario",
  );
  return data.data;
}

export async function getFlowPreview(id: number): Promise<{ preview_url: string; expires_at: string }> {
  const data = await request(`/api/whatsapp-flows/${id}/preview`, { method: "GET" }, "Error al obtener la vista previa");
  return data.data;
}

export async function deleteFlow(id: number): Promise<void> {
  await request(`/api/whatsapp-flows/${id}`, { method: "DELETE" }, "Error al eliminar el formulario");
}

export async function getFlowEncryption(channelId: number): Promise<WhatsAppFlowEncryptionStatus> {
  const data = await request(
    `/api/channels/${channelId}/flows/encryption`,
    { method: "GET" },
    "Error al cargar el estado del cifrado",
  );
  return data.data;
}

export async function setupFlowEncryption(channelId: number): Promise<WhatsAppFlowEncryptionStatus> {
  const data = await request(
    `/api/channels/${channelId}/flows/encryption`,
    { method: "POST" },
    "Error al configurar el cifrado",
  );
  return data.data;
}

export async function sendFlowMessage(conversationId: number, input: SendFlowInput): Promise<Message> {
  const data = await request(
    `/api/conversations/${conversationId}/send-flow`,
    { method: "POST", body: JSON.stringify(input) },
    "Error al enviar el formulario",
  );
  return data.data;
}
