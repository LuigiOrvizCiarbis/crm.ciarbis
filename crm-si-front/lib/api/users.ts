import type { UserRole } from "@/store/useAuthStore"
import { getAuthToken } from "./auth-token"

export interface SystemUser {
  id: number
  name: string
  email?: string
  tenant_id?: number
  role?: UserRole | null
}

/** Igual que getUsers pero lanza si falla, para las pantallas que distinguen "error" de "lista vacía". */
export async function fetchUsers(): Promise<SystemUser[]> {
  const token = getAuthToken()
  if (!token) return []

  const res = await fetch("/api/users", {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  })

  if (!res.ok) {
    const errJson = await res.json().catch(() => ({}))
    console.warn("[getUsers] error", res.status, errJson)
    throw new Error("No se pudieron cargar los usuarios")
  }
  // La API responde un arreglo plano o envuelto en { data }.
  const payload = (await res.json()) as SystemUser[] | { data?: SystemUser[] }
  const users = Array.isArray(payload) ? payload : payload.data || []

  return users.map((u) => ({
    id: u.id,
    name: u.name,
    email: u.email,
    tenant_id: u.tenant_id,
    role: u.role ?? null,
  }))
}

export async function getUsers(): Promise<SystemUser[]> {
  try {
    return await fetchUsers()
  } catch (e) {
    console.error("Error fetching users", e)
    return []
  }
}
