import { useAuthStore, type SavedAccount, type UserRole } from "@/store/useAuthStore"
import { firstAccessibleSection } from "@/lib/section-access"

export const ADD_ACCOUNT_PATH = "/login?add=1"

/** Primera pantalla a la que entra una cuenta: misma regla que después del login. */
export function accountHomePath(account: {
  emailVerified: boolean
  permissions: string[]
  role: UserRole | null
}): string {
  if (!account.emailVerified) return "/verify-email"
  return firstAccessibleSection(account.permissions, account.role)?.href ?? "/sin-acceso"
}

export function accountInitials(user: { name?: string | null; email?: string | null }): string {
  if (user.name) {
    return user.name.split(" ").map((word) => word[0]).join("").toUpperCase().slice(0, 2)
  }
  return user.email?.slice(0, 2).toUpperCase() || "U"
}

export function mostRecentSavedAccount(accounts: SavedAccount[]): SavedAccount | null {
  return accounts.reduce<SavedAccount | null>(
    (latest, account) => (!latest || account.lastUsedAt > latest.lastUsedAt ? account : latest),
    null,
  )
}

/**
 * Activa una cuenta guardada: el servidor cambia el token activo de la cookie
 * httpOnly y recién entonces se actualiza el estado local. La recarga completa
 * es necesaria para que Pusher, SSE y los stores en memoria arranquen con la
 * cuenta nueva. Si el navegador ya no tiene su token, la cuenta se olvida.
 */
export async function switchToAccount(userId: number): Promise<boolean> {
  const response = await fetch("/api/auth/switch", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ userId }),
  }).catch(() => null)

  if (!response?.ok) {
    if (response?.status === 404) useAuthStore.getState().forgetSavedAccount(userId)
    return false
  }

  const account = useAuthStore.getState().activateAccount(userId)
  if (account) window.location.assign(accountHomePath(account))
  return !!account
}

/**
 * Cierra sólo la cuenta activa (el servidor revoca su token) y entra en la
 * guardada usada más recientemente; sin cuentas utilizables vuelve a /login.
 */
export async function signOut(): Promise<void> {
  const { savedAccounts, logout } = useAuthStore.getState()
  await fetch("/api/auth/logout", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ remainingUserIds: savedAccounts.map((account) => account.user.id) }),
  }).catch(() => {
    // Ignorar errores: la sesión local se cierra de todos modos.
  })
  logout()

  let next = mostRecentSavedAccount(useAuthStore.getState().savedAccounts)
  while (next) {
    if (await switchToAccount(next.user.id)) return
    useAuthStore.getState().forgetSavedAccount(next.user.id)
    next = mostRecentSavedAccount(useAuthStore.getState().savedAccounts)
  }
  window.location.replace("/login")
}
