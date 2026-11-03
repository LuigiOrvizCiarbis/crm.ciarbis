import { throwApiError } from "./api-error";
import { getAuthToken, workspaceHeaders } from "./auth-token";

export interface Tag {
  id: number;
  name: string;
  slug: string;
  color: string;
  type: string | null;
  description: string | null;
  is_system: boolean;
  created_at?: string;
  updated_at?: string;
  /** Sólo con getTags({ withCounts: true }). */
  contacts_count?: number;
  conversations_count?: number;
  opportunities_count?: number;
}

export interface TagBlockingReferences {
  automations: { id: number; name: string }[];
  broadcasts: { id: number; name: string; status: string }[];
}

export interface TagUsage {
  contacts_count: number;
  conversations_count: number;
  opportunities_count: number;
  blocking: TagBlockingReferences;
}

/** El backend responde 409 cuando una automatización activa o una difusión pendiente usa la etiqueta. */
export class TagInUseError extends Error {
  constructor(message: string, public blocking: TagBlockingReferences) {
    super(message);
    this.name = "TagInUseError";
  }
}

export interface TagInput {
  name: string;
  color?: string;
  type?: string | null;
  description?: string | null;
}

function requireToken(): string {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");
  return token;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    headers: {
      Authorization: `Bearer ${requireToken()}`,
      Accept: "application/json",
      "Content-Type": "application/json",
      ...workspaceHeaders(),
      ...options.headers,
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al procesar etiquetas");
  }

  return payload as T;
}

export async function getTags(params: { search?: string; type?: string; withCounts?: boolean } = {}): Promise<Tag[]> {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.type) query.set("type", params.type);
  if (params.withCounts) query.set("with_counts", "1");

  const payload = await request<{ data: Tag[] }>(`/api/tags${query.toString() ? `?${query}` : ""}`);

  return payload.data;
}

export async function getTagUsage(tagId: number): Promise<TagUsage> {
  const payload = await request<{ data: TagUsage }>(`/api/tags/${tagId}/usage`);
  return payload.data;
}

export async function createTag(input: TagInput): Promise<Tag> {
  const payload = await request<{ data: Tag }>("/api/tags", {
    method: "POST",
    body: JSON.stringify(input),
  });

  return payload.data;
}

export async function updateTag(tagId: number, input: Partial<TagInput>): Promise<Tag> {
  const payload = await request<{ data: Tag }>(`/api/tags/${tagId}`, {
    method: "PUT",
    body: JSON.stringify(input),
  });

  return payload.data;
}

export async function deleteTag(tagId: number): Promise<void> {
  const response = await fetch(`/api/tags/${tagId}`, {
    method: "DELETE",
    headers: { Authorization: `Bearer ${requireToken()}`, Accept: "application/json", ...workspaceHeaders() },
    cache: "no-store",
  });
  const payload = await response.json().catch(() => ({}));
  if (response.status === 409 && payload?.blocking) {
    throw new TagInUseError(payload.message ?? "La etiqueta está en uso", payload.blocking);
  }
  if (!response.ok) throwApiError(response.status, payload, "No se pudo eliminar la etiqueta");
}

export async function attachContactTags(contactId: number, tagIds: number[]) {
  return request(`/api/contacts/${contactId}/tags`, {
    method: "POST",
    body: JSON.stringify({ tag_ids: tagIds }),
  });
}

export async function detachContactTag(contactId: number, tagId: number) {
  return request(`/api/contacts/${contactId}/tags/${tagId}`, { method: "DELETE" });
}

export async function attachConversationTags(conversationId: number, tagIds: number[]) {
  return request(`/api/conversations/${conversationId}/tags`, {
    method: "POST",
    body: JSON.stringify({ tag_ids: tagIds }),
  });
}

export async function detachConversationTag(conversationId: number, tagId: number) {
  return request(`/api/conversations/${conversationId}/tags/${tagId}`, { method: "DELETE" });
}
