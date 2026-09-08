import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";
import type { Tag } from "./tags";

export interface ContactsSummary {
  total_contacts: number
  new_this_month: number
  active_leads: number
  qualified: number
  won: number
  conversion_rate: number
  // Cobranzas: el backend emite estas tres claves SOLO si el tenant tiene el
  // módulo habilitado (billing_configs.enabled). Su ausencia es la señal de
  // "módulo apagado" — por eso son opcionales y no números en 0, que se
  // confundirían con "todo al día".
  billing_al_dia?: number
  billing_por_vencer?: number
  billing_vencido?: number
}

export interface ContactUpdate {
  name?: string
  phone?: string | null
  email?: string | null
  source?: string
  custom_data?: Record<string, unknown>
}

export interface ContactPipelineStage {
  id: number
  name: string
}

export interface ContactAssignedUser {
  id: number
  name: string
  email: string | null
}

export interface Contact {
  id: number
  name: string
  email: string | null
  phone: string | null
  source: string
  custom_data: Record<string, unknown>
  created_at: string
  updated_at: string
  tags?: Tag[]
  pipeline_stage?: ContactPipelineStage | null
  assigned_user?: ContactAssignedUser | null
}

export interface GetContactsParams {
  search?: string
  source?: string
  tags?: string
  custom?: Record<string, string>
  per_page?: number
}

export async function getContacts(params: GetContactsParams = {}): Promise<Contact[]> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const query = new URLSearchParams();
  query.set("per_page", String(params.per_page ?? 100));
  if (params.search) query.set("search", params.search);
  if (params.source) query.set("source", params.source);
  if (params.tags) query.set("tags", params.tags);
  if (params.custom) {
    for (const [key, value] of Object.entries(params.custom)) {
      if (value !== undefined && value !== "") query.set(`custom[${key}]`, value);
    }
  }

  const response = await fetch(`/api/contacts?${query.toString()}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar contactos");
  }

  return (payload.data ?? payload) as Contact[];
}

export async function getContact(contactId: number): Promise<Contact> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/contacts/${contactId}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar contacto");
  }

  return (payload.data ?? payload) as Contact;
}

export async function getContactsSummary(): Promise<ContactsSummary> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch("/api/contacts/summary", {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar resumen de contactos");
  }

  return payload as ContactsSummary;
}

export async function updateContact(contactId: number, updates: ContactUpdate): Promise<Contact> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/contacts/${contactId}`, {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(updates),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar contacto");
  }

  return (payload.data ?? payload) as Contact;
}

export type BulkTagAction = "add" | "remove" | "replace";

export interface BulkTagsRequest {
  ids: number[];
  action: BulkTagAction;
  tag_ids: number[];
}

export interface BulkTagsResponse {
  updated: number;
  failed: number;
  action: BulkTagAction;
}

export async function bulkUpdateContactTags(req: BulkTagsRequest): Promise<BulkTagsResponse> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch("/api/contacts/bulk-tags", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(req),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar etiquetas en lote");
  }

  return payload as BulkTagsResponse;
}
