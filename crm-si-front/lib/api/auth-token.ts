export function getAuthToken(): string | null {
  const authStorage = localStorage.getItem("auth-storage");
  if (!authStorage) return null;
  try {
    return JSON.parse(authStorage)?.state?.token ?? null;
  } catch {
    return null;
  }
}

const WORKSPACE_KEY = "active-workspace-id"

/** Contexto por pestaña; el cookie permite que los proxies Next lo reenvíen. */
export function getWorkspaceId(): number | null {
  if (typeof window === "undefined") return null
  const value = window.sessionStorage.getItem(WORKSPACE_KEY) || document.cookie.match(/(?:^|; )active-workspace-id=([^;]+)/)?.[1]
  const id = value ? Number(value) : NaN
  return Number.isInteger(id) && id > 0 ? id : null
}

export function setWorkspaceId(id: number): void {
  if (typeof window === "undefined") return
  window.sessionStorage.setItem(WORKSPACE_KEY, String(id))
  window.localStorage.setItem("last-workspace-id", String(id))
  document.cookie = `${WORKSPACE_KEY}=${id}; Path=/; SameSite=Lax`
}

export function clearWorkspaceId(): void {
  if (typeof window === "undefined") return
  window.sessionStorage.removeItem(WORKSPACE_KEY)
  document.cookie = `${WORKSPACE_KEY}=; Max-Age=0; Path=/; SameSite=Lax`
}

export function workspaceHeaders(): Record<string, string> {
  const id = getWorkspaceId()
  return id ? { "X-Workspace-Id": String(id) } : {}
}
