export interface Role {
  id: number
  name: string
  is_system: boolean
  is_owner?: boolean
  permissions: string[]
  created_at?: string
  updated_at?: string
}

export interface PermissionGroup {
  resource: string
  items: string[]
}

export async function getRoles(): Promise<Role[]> {
  const res = await fetch("/api/roles", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data || []
}

export async function createRole(name: string, permissions: string[] = []): Promise<{ data?: Role; error?: string }> {
  const res = await fetch("/api/roles", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ name, permissions }),
  })

  const json = await res.json()
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function updateRole(
  id: number,
  payload: { name?: string; permissions?: string[] },
): Promise<{ data?: Role; error?: string }> {
  const res = await fetch(`/api/roles/${id}`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(payload),
  })

  const json = await res.json()
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}

export async function deleteRole(id: number): Promise<{ error?: string }> {
  const res = await fetch(`/api/roles/${id}`, {
    method: "DELETE",
    headers: { Accept: "application/json" },
  })

  if (!res.ok) {
    const json = await res.json().catch(() => ({}))
    return { error: json.message || "Error" }
  }
  return {}
}

export async function getPermissions(): Promise<PermissionGroup[]> {
  const res = await fetch("/api/permissions", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data || []
}

export async function assignUserRole(userId: number, roleName: string): Promise<{ data?: any; error?: string }> {
  const res = await fetch(`/api/users/${userId}/role`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ role_name: roleName }),
  })

  const json = await res.json()
  if (!res.ok) return { error: json.message || "Error" }
  return { data: json.data }
}
