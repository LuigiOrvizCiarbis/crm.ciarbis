import type { AdReferral } from "@/data/types";
import { workspaceHeaders } from "./auth-token";
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

/** `manual` la subió un agente; el resto la trajo el canal y se refresca sola. */
export type ContactAvatarSource = "manual" | "facebook" | "instagram"

export interface ContactAvatar {
  avatar_url: string | null
  avatar_source: ContactAvatarSource | null
}

export interface Contact {
  id: number
  name: string
  email: string | null
  phone: string | null
  source: string
  /** Absoluta; null cuando no hay foto (WhatsApp sólo admite foto manual). */
  avatar_url: string | null
  avatar_source: ContactAvatarSource | null
  custom_data: Record<string, unknown>
  created_at: string
  updated_at: string
  tags?: Tag[]
  pipeline_stage?: ContactPipelineStage | null
  assigned_user?: ContactAssignedUser | null
  ad_referrals?: AdReferral[]
}

export interface GetContactsParams {
  search?: string
  source?: string
  tags?: string
  custom?: Record<string, string>
  per_page?: number
}

export async function getContacts(params: GetContactsParams = {}): Promise<Contact[]> {
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
      Accept: "application/json",
      ...workspaceHeaders(),
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
  const response = await fetch(`/api/contacts/${contactId}`, {
    headers: {
      Accept: "application/json",
      ...workspaceHeaders(),
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
  const response = await fetch("/api/contacts/summary", {
    headers: {
      Accept: "application/json",
      ...workspaceHeaders(),
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
  const response = await fetch(`/api/contacts/${contactId}`, {
    method: "PUT",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...workspaceHeaders(),
    },
    body: JSON.stringify(updates),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar contacto");
  }

  return (payload.data ?? payload) as Contact;
}

export async function uploadContactAvatar(contactId: number, file: File): Promise<ContactAvatar> {
  const formData = new FormData();
  formData.append("avatar", file);

  const response = await fetch(`/api/contacts/${contactId}/avatar`, {
    method: "POST",
    // Sin Content-Type: el browser pone el boundary del multipart.
    headers: { Accept: "application/json", ...workspaceHeaders() },
    body: formData,
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "No se pudo subir la foto");
  }

  return payload.data as ContactAvatar;
}

/** Quita la foto manual; si el canal tiene una, vuelve a mostrarse esa. */
export async function deleteContactAvatar(contactId: number): Promise<ContactAvatar> {
  const response = await fetch(`/api/contacts/${contactId}/avatar`, {
    method: "DELETE",
    headers: { Accept: "application/json", ...workspaceHeaders() },
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "No se pudo quitar la foto");
  }

  return payload.data as ContactAvatar;
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

export interface BulkFieldsRequest {
  ids: number[];
  updates: ContactUpdate;
}

export interface BulkFieldsResponse {
  updated: number;
  failed: number;
  failures: Array<{ id: number; reason: string }>;
}

export async function bulkUpdateContactFields(req: BulkFieldsRequest): Promise<BulkFieldsResponse> {
  const response = await fetch("/api/contacts/bulk-fields", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...workspaceHeaders(),
    },
    body: JSON.stringify(req),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar contactos en lote");
  }

  return payload as BulkFieldsResponse;
}

export async function bulkUpdateContactTags(req: BulkTagsRequest): Promise<BulkTagsResponse> {
  const response = await fetch("/api/contacts/bulk-tags", {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...workspaceHeaders(),
    },
    body: JSON.stringify(req),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar etiquetas en lote");
  }

  return payload as BulkTagsResponse;
}
