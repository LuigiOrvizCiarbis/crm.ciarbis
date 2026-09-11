import { getAuthToken, setWorkspaceId, workspaceHeaders } from "./auth-token"

export interface WorkspaceSummary {
  id: number
  name: string
  role?: string | null
  branch_id?: number | null
  branch_name?: string | null
  plan?: { key: string; name: string } | null
  trial_ends_at?: string | null
  joined_at?: string
}

export async function getWorkspaces(): Promise<WorkspaceSummary[]> {
  const token = getAuthToken()
  if (!token) return []
  const response = await fetch("/api/workspaces", {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json", ...workspaceHeaders() },
    cache: "no-store",
  })
  if (!response.ok) return []
  return ((await response.json())?.data ?? []) as WorkspaceSummary[]
}

export async function createWorkspace(name: string): Promise<{ data?: WorkspaceSummary; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }
  const response = await fetch("/api/workspaces", {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json", Accept: "application/json", ...workspaceHeaders() },
    body: JSON.stringify({ name }),
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) return { error: payload.message || "No se pudo crear el workspace" }
  setWorkspaceId(payload.data.id)
  return { data: payload.data }
}
