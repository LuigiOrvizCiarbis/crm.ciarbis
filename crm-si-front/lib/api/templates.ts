import { WhatsAppTemplate } from "@/data/types";
import { workspaceHeaders } from "./auth-token";
import { throwApiError } from "./api-error";

export async function getTemplates(channelId: number): Promise<WhatsAppTemplate[]> {
  const res = await fetch(`/api/channels/${channelId}/templates`, {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      ...workspaceHeaders(),
    },
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al cargar plantillas");

  return Array.isArray(data) ? data : data.data ?? [];
}

export async function getManagedTemplates(channelId: number): Promise<WhatsAppTemplate[]> {
  const res = await fetch(`/api/channels/${channelId}/templates?status=all`, {
    headers: { ...workspaceHeaders() },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al cargar plantillas");
  return Array.isArray(data) ? data : data.data ?? [];
}

export interface CreateTemplatePayload {
  name: string;
  language: string;
  category: "MARKETING" | "UTILITY";
  parameter_format: "named";
  components: unknown[];
}

export async function createTemplate(channelId: number, payload: CreateTemplatePayload): Promise<WhatsAppTemplate> {
  const res = await fetch(`/api/channels/${channelId}/templates`, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...workspaceHeaders() },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al crear la plantilla");
  return data.data ?? data;
}

export async function uploadTemplateHeader(channelId: number, file: File): Promise<{ header_handle: string }> {
  const body = new FormData();
  body.append("file", file);
  const res = await fetch(`/api/channels/${channelId}/templates/media`, {
    method: "POST",
    headers: { ...workspaceHeaders() },
    body,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al cargar el archivo de ejemplo");
  return data;
}

export async function syncTemplates(channelId: number): Promise<{ message: string; count: number }> {
  const res = await fetch(`/api/channels/${channelId}/templates/sync`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...workspaceHeaders(),
    },
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al sincronizar plantillas");

  return data;
}

export async function deleteTemplate(channelId: number, templateId: number): Promise<void> {
  const res = await fetch(`/api/channels/${channelId}/templates/${templateId}`, {
    method: "DELETE",
    headers: {
      Accept: "application/json",
      ...workspaceHeaders(),
    },
  });

  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throwApiError(res.status, data, "Error al eliminar la plantilla");
  }
}

/**
 * Sube un archivo a Meta (vía backend) para usarlo como header de plantilla.
 * Devuelve el media id de Meta (válido ~30 días).
 */
export async function uploadTemplateMedia(
  channelId: number,
  file: File,
): Promise<{ media_id: string }> {
  const formData = new FormData();
  formData.append("file", file);

  const res = await fetch(`/api/channels/${channelId}/media`, {
    method: "POST",
    headers: {
      ...workspaceHeaders(),
    },
    body: formData,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al subir el archivo");

  return data;
}

export async function sendTemplate(
  conversationId: number,
  templateId: number,
  components: any[] = []
) {
  const res = await fetch(`/api/conversations/${conversationId}/send-template`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...workspaceHeaders(),
    },
    body: JSON.stringify({
      template_id: templateId,
      components,
    }),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "Error al enviar plantilla");

  return data;
}
