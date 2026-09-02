import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export type WhatsAppGroupStatus = "pending" | "active" | "suspended" | "deleted" | "failed"
export type WhatsAppGroupParticipantStatus = "invited" | "active" | "removed" | "pending_approval" | "rejected"

export interface WhatsAppGroupParticipant {
  id: number
  contact_id: number | null
  wa_id: string | null
  display_name: string | null
  role: string
  status: WhatsAppGroupParticipantStatus
  join_request_id: string | null
  joined_at: string | null
  removed_at: string | null
}

export interface WhatsAppGroup {
  id: number
  channel_id: number
  conversation_id: number | null
  opportunity_id: number | null
  group_id: string | null
  subject: string
  description: string | null
  join_approval_mode: "approval_required" | "auto_approve"
  invite_link: string | null
  status: WhatsAppGroupStatus
  suspended: boolean
  total_participant_count: number
  profile_picture_url: string | null
  error_message: string | null
  participants?: WhatsAppGroupParticipant[]
  created_at: string
}

export interface WhatsAppGroupEligibility {
  status: "eligible" | "not_oba" | "on_biz_app" | "token_invalid" | "unknown"
  is_oba: boolean | null
  platform_type: string | null
  checked_at: string | null
  reason_message: string
}

export interface WhatsAppGroupInviteTemplate {
  id: number
  name: string
  language: string
  category: string
  status: string
}

export interface CreateWhatsAppGroupPayload {
  channel_id: number
  subject: string
  description?: string
  join_approval_mode?: "approval_required" | "auto_approve"
  opportunity_id?: number
}

export interface InviteToGroupPayload {
  template_id: number
  invitees: Array<{ contact_id?: number; phone?: string; name?: string }>
}

function token(): string {
  const value = getAuthToken()
  if (!value) throw new Error("No authentication token found")
  return value
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    headers: {
      Authorization: `Bearer ${token()}`,
      Accept: "application/json",
      "Content-Type": "application/json",
      ...options.headers,
    },
    cache: "no-store",
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throwApiError(response.status, payload, "Error al procesar el grupo")
  return payload as T
}

export async function getGroupsEligibility(channelId: number): Promise<WhatsAppGroupEligibility> {
  const result = await request<{ data: WhatsAppGroupEligibility }>(`/api/channels/${channelId}/groups-eligibility`)
  return result.data
}

export async function getWhatsAppGroups(params?: { channel_id?: number; status?: WhatsAppGroupStatus }): Promise<WhatsAppGroup[]> {
  const search = new URLSearchParams()
  if (params?.channel_id) search.set("channel_id", String(params.channel_id))
  if (params?.status) search.set("status", params.status)
  const query = search.toString() ? `?${search.toString()}` : ""
  const result = await request<{ data: WhatsAppGroup[] }>(`/api/whatsapp-groups${query}`)
  return result.data
}

export async function getWhatsAppGroup(id: number): Promise<WhatsAppGroup> {
  const result = await request<{ data: WhatsAppGroup }>(`/api/whatsapp-groups/${id}`)
  return result.data
}

export async function createWhatsAppGroup(payload: CreateWhatsAppGroupPayload): Promise<WhatsAppGroup> {
  const result = await request<{ data: WhatsAppGroup }>("/api/whatsapp-groups", {
    method: "POST",
    body: JSON.stringify(payload),
  })
  return result.data
}

export async function updateWhatsAppGroup(id: number, payload: { subject?: string; description?: string | null }): Promise<WhatsAppGroup> {
  const result = await request<{ data: WhatsAppGroup }>(`/api/whatsapp-groups/${id}`, {
    method: "PATCH",
    body: JSON.stringify(payload),
  })
  return result.data
}

export async function deleteWhatsAppGroup(id: number): Promise<void> {
  await request(`/api/whatsapp-groups/${id}`, { method: "DELETE" })
}

export async function syncWhatsAppGroup(id: number): Promise<WhatsAppGroup> {
  const result = await request<{ data: WhatsAppGroup }>(`/api/whatsapp-groups/${id}/sync`, { method: "POST" })
  return result.data
}

export async function getGroupInviteLink(id: number): Promise<string> {
  const result = await request<{ data: { invite_link: string } }>(`/api/whatsapp-groups/${id}/invite-link`)
  return result.data.invite_link
}

export async function resetGroupInviteLink(id: number): Promise<string> {
  const result = await request<{ data: { invite_link: string } }>(`/api/whatsapp-groups/${id}/invite-link`, { method: "POST" })
  return result.data.invite_link
}

export async function getGroupJoinRequests(id: number): Promise<Array<{ wa_id: string; join_request_id?: string }>> {
  const result = await request<{ data: Array<{ wa_id: string; join_request_id?: string }> }>(`/api/whatsapp-groups/${id}/join-requests`)
  return result.data
}

export async function approveGroupJoinRequests(id: number, waIds: string[]): Promise<void> {
  await request(`/api/whatsapp-groups/${id}/join-requests/approve`, {
    method: "POST",
    body: JSON.stringify({ wa_ids: waIds }),
  })
}

export async function rejectGroupJoinRequests(id: number, waIds: string[]): Promise<void> {
  await request(`/api/whatsapp-groups/${id}/join-requests/reject`, {
    method: "POST",
    body: JSON.stringify({ wa_ids: waIds }),
  })
}

export async function removeGroupParticipants(id: number, participants: string[]): Promise<void> {
  await request(`/api/whatsapp-groups/${id}/participants/remove`, {
    method: "POST",
    body: JSON.stringify({ participants }),
  })
}

export async function getGroupInviteTemplates(id: number): Promise<WhatsAppGroupInviteTemplate[]> {
  const result = await request<{ data: WhatsAppGroupInviteTemplate[] }>(`/api/whatsapp-groups/${id}/invite-templates`)
  return result.data
}

export async function inviteToGroup(id: number, payload: InviteToGroupPayload): Promise<void> {
  await request(`/api/whatsapp-groups/${id}/invitations`, {
    method: "POST",
    body: JSON.stringify(payload),
  })
}
