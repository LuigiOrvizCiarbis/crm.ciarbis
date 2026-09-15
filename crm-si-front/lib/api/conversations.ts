import type { Conversation, TranslationLanguage } from "@/data/types";
import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";

function mapConversation(c: any): Conversation {
  return {
    id: c.id,
    channelId: c.channel_id,
    contact: c.contact,
    last_message: c.last_message_preview || c.last_message || "",
    timestamp: c.last_message_at || c.updated_at || c.created_at || "",
    unread: Boolean(c.unread ?? ((c.unread_count && c.unread_count > 0) || c.manual_unread)),
    manual_unread: Boolean(c.manual_unread),
    leadScore: c.lead_score ?? undefined,
    pipeline_stage_id: c.pipeline_stage_id,
    priority: c.priority,
    assigneeId: c.assigned_to,
    archived: c.archived,
    aiAutoreplyEnabled: Boolean(c.ai_autoreply_enabled),
    contactLanguage: c.contact_language ?? undefined,
    channel: c.channel,
    last_message_at: c.last_message_at,
    created_at: c.created_at,
    unread_count: c.unread_count,
    messages: c.messages,
    tags: c.tags,
    matchedMessageSnippet: c.matched_message_snippet ?? undefined,
    humanHandoff: c.human_handoff ?? null,
  };
}

export async function acknowledgeHumanHandoff(conversationId: number) {
  const token = requireToken();
  const res = await fetch(`/api/conversations/${conversationId}/human-handoff/acknowledge`, {
    method: "POST", headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  });
  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "No se pudo tomar la derivación");
  return payload.data;
}

function requireToken(): string {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");
  return token;
}

async function fetchConversationsPage(params: URLSearchParams, token: string) {
  const query = params.toString();
  const response = await fetch(`/api/conversations${query ? `?${query}` : ""}`, {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    cache: "no-store",
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "Error al cargar conversaciones");
  }

  const json = await response.json();
  return {
    data: (json.data || []).map(mapConversation),
    currentPage: Number(json.meta?.current_page ?? params.get("page") ?? 1),
    lastPage: Number(json.meta?.last_page ?? 1),
  };
}

async function getAllConversations(params: URLSearchParams = new URLSearchParams()): Promise<Conversation[]> {
  const token = requireToken();
  const perPage = params.get("per_page") || "100";
  const firstPageParams = new URLSearchParams(params);

  firstPageParams.set("page", firstPageParams.get("page") || "1");
  firstPageParams.set("per_page", perPage);

  const firstPage = await fetchConversationsPage(firstPageParams, token);
  if (firstPage.lastPage <= firstPage.currentPage) return firstPage.data;

  const remainingPages = Array.from(
    { length: firstPage.lastPage - firstPage.currentPage },
    (_, index) => firstPage.currentPage + index + 1
  );

  const remaining = await Promise.all(
    remainingPages.map((page) => {
      const pageParams = new URLSearchParams(params);
      pageParams.set("page", String(page));
      pageParams.set("per_page", perPage);
      return fetchConversationsPage(pageParams, token);
    })
  );

  return [firstPage, ...remaining].flatMap((page) => page.data);
}

export async function getConversations(): Promise<Conversation[]> {
  return getAllConversations();
}

export async function searchConversationsByContent(
  query: string,
  options: { channelId?: number | null } = {},
): Promise<Conversation[]> {
  const params = new URLSearchParams({ search: query, per_page: "50" });
  if (options.channelId) params.set("channel_id", String(options.channelId));
  const { data } = await fetchConversationsPage(params, requireToken());
  return data;
}

export async function getConversationUnreadCount(): Promise<number> {
  const token = requireToken();
  const response = await fetch("/api/conversations?summary=unread_count", {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar conversaciones no leídas");
  }

  return Number(payload.data?.unread_count ?? 0);
}

export async function markConversationAsRead(conversationId: number): Promise<number> {
  const token = requireToken();
  const response = await fetch(`/api/conversations/${conversationId}/read`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al marcar conversación como leída");
  }

  return Number(payload.data?.marked ?? 0);
}

export async function markConversationAsUnread(conversationId: number): Promise<number> {
  const token = requireToken();
  const response = await fetch(`/api/conversations/${conversationId}/unread`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al marcar conversación como no leída");
  }

  return Number(payload.data?.marked ?? 0);
}

export async function getChannelConversations(channelId: number) {
  const params = new URLSearchParams();
  params.set("channel_id", String(channelId));

  return getAllConversations(params);
}

export async function getUserConversations(userId: number) {
  const params = new URLSearchParams();
  params.set("user_id", String(userId));

  return getAllConversations(params);
}

export async function getConversationWithMessages(conversationId: number): Promise<Conversation> {
  const token = requireToken();

  const res = await fetch(`/api/conversations/${conversationId}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "Error al cargar conversación");

  return mapConversation(payload.data);
}

export async function getConversationMessages(conversationId: number, page: number = 1) {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const res = await fetch(`/api/conversations/${conversationId}/messages?page=${page}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "Error al cargar historial de mensajes");

  return payload;
}

export async function updateConversationStage(conversationId: number, stage: number | string) {
  const token = getAuthToken();
  if (!token) throw new Error("No token found");

  const body = typeof stage === 'number'
    ? { pipeline_stage_id: stage }
    : { pipeline_stage_id: Number(stage) };

  const res = await fetch(`/api/conversations/${conversationId}/stage`, {
    method: "PATCH",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const errorData = await res.json().catch(() => ({}));
    throwApiError(res.status, errorData, "Error al actualizar etapa");
  }

  return await res.json();
}

export async function archiveConversation(conversationId: number, archived: boolean) {
  const token = getAuthToken();
  if (!token) throw new Error("No token found");

  const res = await fetch(`/api/conversations/${conversationId}/archive`, {
    method: "PATCH",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ archived }),
  });

  if (!res.ok) {
    const errorData = await res.json().catch(() => ({}));
    throwApiError(res.status, errorData, "Error al archivar conversación");
  }

  const payload = await res.json();
  return payload.data as { id: number; archived: boolean; archived_at: string | null };
}

export async function setAiAutoreply(conversationId: number, enabled: boolean) {
  const token = getAuthToken();
  if (!token) throw new Error("No token found");

  const res = await fetch(`/api/conversations/${conversationId}/ai-autoreply`, {
    method: "PATCH",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ enabled }),
  });

  if (!res.ok) {
    const errorData = await res.json().catch(() => ({}));
    throwApiError(res.status, errorData, "Error al actualizar la respuesta IA");
  }

  const payload = await res.json();
  return payload.data as { id: number; ai_autoreply_enabled: boolean };
}

export async function translateDraft(
  conversationId: number,
  content: string,
  targetLanguage: TranslationLanguage,
): Promise<{ translated_content: string; target_language: TranslationLanguage }> {
  const token = requireToken();
  const res = await fetch(`/api/conversations/${conversationId}/translate-draft`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ content, target_language: targetLanguage }),
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "No se pudo traducir el borrador");
  return payload.data;
}

export async function setConversationTranslationLanguage(
  conversationId: number,
  contactLanguage: TranslationLanguage,
): Promise<{ id: number; contact_language: TranslationLanguage }> {
  const token = requireToken();
  const res = await fetch(`/api/conversations/${conversationId}/translation-language`, {
    method: "PATCH",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ contact_language: contactLanguage }),
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "No se pudo guardar el idioma del contacto");
  return payload.data;
}

export type BulkConversationTagAction = "add" | "remove" | "replace";

export interface BulkConversationTagsRequest {
  ids: number[];
  action: BulkConversationTagAction;
  tag_ids: number[];
}

export interface BulkConversationTagsResponse {
  updated: number;
  failed: number;
  action: BulkConversationTagAction;
}

export async function bulkUpdateConversationTags(
  req: BulkConversationTagsRequest,
): Promise<BulkConversationTagsResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-tags", {
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

  return payload as BulkConversationTagsResponse;
}

export interface BulkAssignConversationsRequest {
  ids: number[];
  user_id: number;
}

export interface BulkAssignConversationsResponse {
  updated: number;
  failed: number;
  assigned_to: number;
}

export async function bulkAssignConversations(
  req: BulkAssignConversationsRequest,
): Promise<BulkAssignConversationsResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-assign", {
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
    throwApiError(response.status, payload, "Error al asignar conversaciones");
  }

  return payload as BulkAssignConversationsResponse;
}

export interface BulkBroadcastRequest {
  ids: number[];
  template_id: number;
  components: unknown[];
}

export interface BulkBroadcastResponse {
  queued: number;
  failed: number;
}

export async function bulkBroadcastConversations(
  req: BulkBroadcastRequest,
): Promise<BulkBroadcastResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-broadcast", {
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
    throwApiError(response.status, payload, "Error al enviar la difusión");
  }

  return payload as BulkBroadcastResponse;
}

export interface BulkArchiveConversationsRequest {
  ids: number[];
  archived: boolean;
}

export interface BulkArchiveConversationsResponse {
  updated: number;
  failed: number;
  archived: boolean;
}

export async function bulkArchiveConversations(
  req: BulkArchiveConversationsRequest,
): Promise<BulkArchiveConversationsResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-archive", {
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
    throwApiError(response.status, payload, "Error al archivar conversaciones");
  }

  return payload as BulkArchiveConversationsResponse;
}

export interface BulkAiAutoreplyConversationsRequest {
  ids: number[];
  enabled: boolean;
}

export interface BulkAiAutoreplyConversationsResponse {
  updated: number;
  failed: number;
  enabled: boolean;
}

export async function bulkAiAutoreplyConversations(
  req: BulkAiAutoreplyConversationsRequest,
): Promise<BulkAiAutoreplyConversationsResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-ai-autoreply", {
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
    throwApiError(response.status, payload, "Error al actualizar el asistente de IA");
  }

  return payload as BulkAiAutoreplyConversationsResponse;
}

export interface BulkDeleteConversationsRequest {
  ids: number[];
}

export interface BulkDeleteConversationsResponse {
  deleted: number;
  failed: number;
}

export async function bulkDeleteConversations(
  req: BulkDeleteConversationsRequest,
): Promise<BulkDeleteConversationsResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-delete", {
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
    throwApiError(response.status, payload, "Error al eliminar conversaciones");
  }

  return payload as BulkDeleteConversationsResponse;
}

export interface BulkMarkReadConversationsRequest {
  ids: number[];
  read: boolean;
}

export interface BulkMarkReadConversationsResponse {
  updated: number;
  failed: number;
  read: boolean;
}

export async function bulkMarkReadConversations(
  req: BulkMarkReadConversationsRequest,
): Promise<BulkMarkReadConversationsResponse> {
  const token = requireToken();

  const response = await fetch("/api/conversations/bulk-read", {
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
    throwApiError(response.status, payload, "Error al actualizar el estado de lectura");
  }

  return payload as BulkMarkReadConversationsResponse;
}

export async function assignConversationUser(conversationId: number, userId: number) {
  const token = getAuthToken();
  if (!token) throw new Error("No token found");

  const res = await fetch(`/api/conversations/${conversationId}/users/add`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ user_id: userId }),
  });

  if (!res.ok) {
    const errorData = await res.json().catch(() => ({}));
    throwApiError(res.status, errorData, "Error al asignar usuario");
  }

  return await res.json();
}
