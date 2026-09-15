import { getAuthToken, setWorkspaceId, workspaceHeaders } from "./auth-token"

export interface WorkspaceSummary {
  id: number
  name: string
  role?: string | null
  branch_id?: number | null
  branch_name?: string | null
  plan?: { key: string; name: string } | null
  trial_ends_at?: string | null
  /** `pending_deletion` habilita restaurar durante la ventana de 30 días. */
  status?: string | null
  deletion_scheduled_at?: string | null
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

/**
 * Laravel devuelve `errors` en 422 y `message` en el resto. Tomamos el primer
 * error de validación porque es el accionable: dice qué campo corregir.
 */
function readError(payload: any, fallback: string): string {
  const firstError = payload?.errors && typeof payload.errors === "object"
    ? Object.values(payload.errors).flat()[0]
    : null
  return String(firstError || payload?.message || fallback)
}

function authedHeaders(token: string): HeadersInit {
  return {
    Authorization: `Bearer ${token}`,
    "Content-Type": "application/json",
    Accept: "application/json",
    ...workspaceHeaders(),
  }
}

export async function renameWorkspace(
  id: number,
  name: string,
): Promise<{ data?: WorkspaceSummary; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch(`/api/workspaces/${id}`, {
    method: "PATCH",
    headers: authedHeaders(token),
    body: JSON.stringify({ name }),
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) return { error: readError(payload, "No se pudo renombrar el workspace.") }
  return { data: payload.data }
}

/**
 * Desactiva el workspace: el backend lo marca `pending_deletion` y lo purga a
 * los 30 días, así que es reversible con `restoreWorkspace` dentro de esa
 * ventana. Exige el nombre exacto y la contraseña del usuario.
 */
export async function deactivateWorkspace(
  id: number,
  nameConfirmation: string,
  password: string,
): Promise<{ message?: string; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch(`/api/workspaces/${id}`, {
    method: "DELETE",
    headers: authedHeaders(token),
    body: JSON.stringify({ name_confirmation: nameConfirmation, password }),
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) return { error: readError(payload, "No se pudo desactivar el workspace.") }
  return { message: payload.message }
}

export async function restoreWorkspace(id: number): Promise<{ message?: string; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch(`/api/workspaces/${id}/restore`, {
    method: "POST",
    headers: authedHeaders(token),
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) return { error: readError(payload, "No se pudo restaurar el workspace.") }
  return { message: payload.message }
}

export async function leaveWorkspace(id: number): Promise<{ message?: string; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch(`/api/workspaces/${id}/leave`, {
    method: "POST",
    headers: authedHeaders(token),
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) return { error: readError(payload, "No se pudo salir del workspace.") }
  return { message: payload.message }
}
