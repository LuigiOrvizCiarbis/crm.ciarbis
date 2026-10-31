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

export async function getTags(params: { search?: string; type?: string } = {}): Promise<Tag[]> {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.type) query.set("type", params.type);

  const payload = await request<{ data: Tag[] }>(`/api/tags${query.toString() ? `?${query}` : ""}`);

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
  await request(`/api/tags/${tagId}`, { method: "DELETE" });
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
