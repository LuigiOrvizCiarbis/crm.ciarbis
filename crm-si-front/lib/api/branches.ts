
export interface Branch {
  id: number
  tenant_id: number
  name: string
  address?: string | null
  phone?: string | null
  email?: string | null
  timezone?: string | null
  business_hours?: Record<string, { open: string; close: string }> | null
  is_active: boolean
  manager_user_id?: number | null
  created_at?: string
  updated_at?: string
}

export interface BranchStats {
  id?: number
  name?: string
  is_active?: boolean
  contacts_total: number
  conversations_open: number
  opportunities_count: number
  opportunities_value: number
  opportunities_won: number
}

export interface BranchPayload {
  name?: string
  address?: string | null
  phone?: string | null
  email?: string | null
  timezone?: string | null
  business_hours?: Record<string, { open: string; close: string }> | null
  is_active?: boolean
  manager_user_id?: number | null
}

export async function getBranches(): Promise<Branch[]> {
  const res = await fetch("/api/branches", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data || []
}

export async function getBranch(id: number): Promise<{ data?: Branch; error?: string }> {
  const res = await fetch(`/api/branches/${id}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function createBranch(payload: BranchPayload): Promise<{ data?: Branch; error?: string }> {
  const res = await fetch("/api/branches", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(payload),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function updateBranch(
  id: number,
  payload: BranchPayload,
): Promise<{ data?: Branch; error?: string }> {
  const res = await fetch(`/api/branches/${id}`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(payload),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function deleteBranch(id: number): Promise<{ error?: string }> {
  const res = await fetch(`/api/branches/${id}`, {
    method: "DELETE",
    headers: { Accept: "application/json" },
  })

  if (!res.ok) {
    const json = await res.json().catch(() => ({}))
    return { error: json.message || "Error" }
  }
  return {}
}

export async function getBranchStats(
  id: number,
  rango: 7 | 30 | 90 = 30,
): Promise<{ data?: BranchStats; error?: string }> {
  const res = await fetch(`/api/branches/${id}/stats?rango=${rango}`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function assignUserBranch(
  userId: number,
  branchId: number | null,
): Promise<{ data?: any; error?: string }> {
  const res = await fetch(`/api/users/${userId}/branch`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ branch_id: branchId }),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function assignChannelBranch(
  channelId: number,
  branchId: number | null,
): Promise<{ data?: any; error?: string }> {
  const res = await fetch(`/api/channels/${channelId}/branch`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ branch_id: branchId }),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}
