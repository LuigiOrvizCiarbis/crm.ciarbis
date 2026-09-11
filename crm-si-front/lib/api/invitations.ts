import type { UserRole } from "@/store/useAuthStore"
import { getAuthToken } from "./auth-token"

export interface Invitation {
  id: number
  email: string
  role_name: string
  invited_by: number
  invited_by_user?: { id: number; name: string; email: string }
  expires_at: string
  accepted_at: string | null
  created_at: string
}

export interface InvitationDetails {
  email: string
  role_name: string
  tenant_name: string
  inviter_name: string
  expires_at: string
}

export async function getInvitations(): Promise<Invitation[]> {
  const token = getAuthToken()
  if (!token) return []

  const res = await fetch("/api/invitations", {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data || []
}

export async function createInvitation(email: string, roleName: string): Promise<{ data?: Invitation; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch("/api/invitations", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ email, role_name: roleName }),
  })

  const json = await res.json()
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function deleteInvitation(id: number): Promise<{ error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No auth" }

  const res = await fetch(`/api/invitations/${id}`, {
    method: "DELETE",
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  })

  if (!res.ok) {
    const json = await res.json().catch(() => ({}))
    return { error: json.message || "Error" }
  }
  return {}
}

export async function getInvitationByToken(token: string): Promise<{ data?: InvitationDetails; error?: string }> {
  const res = await fetch(`/api/invitations/by-token/${token}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  const json = await res.json()
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function acceptInvitation(
  invitationToken: string,
): Promise<{ token?: string; user?: any; workspace?: { id: number; name: string }; role?: UserRole | null; permissions?: string[]; error?: string }> {
  const authToken = getAuthToken()
  if (!authToken) return { error: "No auth" }

  const res = await fetch("/api/invitations/accept", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${authToken}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ token: invitationToken }),
  })

  const json = await res.json()
  if (!res.ok) return { error: json.message || "Error" }
  return { token: json.token, user: json.user, workspace: json.workspace, role: json.role ?? null, permissions: json.permissions ?? [] }
}
