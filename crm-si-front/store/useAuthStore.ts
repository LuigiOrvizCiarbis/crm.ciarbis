import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"
import * as Sentry from "@sentry/nextjs"
import { disconnectPusher } from "@/lib/pusher"
import { clearWorkspaceId, setWorkspaceId } from "@/lib/api/auth-token"
import type { NavigationLabels } from "@/data/navigation"
import type { WorkspaceSummary } from "@/lib/api/workspaces"
import type { NotificationPreferences } from "@/lib/api/notifications"

/**
 * Identifica al usuario en Sentry para que cada error reportado indique
 * qué usuario y qué tenant (cliente) lo sufrió — clave para soporte.
 */
function setSentryUser(user: User | null) {
  if (!user) {
    Sentry.setUser(null)
    return
  }
  Sentry.setUser({ id: user.id, email: user.email, username: user.name })
  if (user.tenant_id != null) {
    Sentry.setTag("tenant_id", String(user.tenant_id))
  }
}

export interface UserPlan {
  id: number
  key: "free" | "pro" | "enterprise"
  name: string
}

export interface UserTenant {
  id: number
  name: string
  plan?: UserPlan | null
  trial_ends_at?: string | null
  navigation_labels?: NavigationLabels | null
}

export interface UserRole {
  id: number
  name: string
  is_system: boolean
  is_owner?: boolean
}

export interface UserPreferences {
  locale: string
  timezone: string
  date_format: string
  notifications?: NotificationPreferences
}

export interface User {
  id: number
  name: string
  email: string
  email_verified_at?: string | null
  tenant_id?: number
  tenant?: UserTenant | null
  branch_id?: number | null
  phone?: string | null
  job_title?: string | null
  avatar_url?: string | null
  preferences?: UserPreferences | null
  created_at?: string
  updated_at?: string
  workspaces?: WorkspaceSummary[]
}

export interface SavedAccount {
  user: User
  role: UserRole | null
  permissions: string[]
  emailVerified: boolean
  rememberMe: boolean
  /** Epoch ms en que dejó de ser la cuenta activa. */
  lastUsedAt: number
}

interface AuthState {
  user: User | null
  role: UserRole | null
  permissions: string[]
  isAuthenticated: boolean
  emailVerified: boolean
  isLoading: boolean
  rememberMe: boolean
  _hasHydrated: boolean
  workspaces: WorkspaceSummary[]
  activeWorkspaceId: number | null
  /** Cuentas inactivas guardadas en este navegador; nunca incluye la activa. */
  savedAccounts: SavedAccount[]

  // Actions
  setAuth: (
    user: User,
    rememberMe?: boolean,
    emailVerified?: boolean,
    role?: UserRole | null,
    permissions?: string[],
  ) => void
  setEmailVerified: (verified: boolean) => void
  updateUser: (user: Partial<User>) => void
  setRoleAndPermissions: (role: UserRole | null, permissions: string[]) => void
  logout: () => void
  setLoading: (loading: boolean) => void
  setHasHydrated: (state: boolean) => void
  setWorkspaces: (workspaces: WorkspaceSummary[]) => void
  setActiveWorkspace: (id: number) => void
  /** Sólo estado del cliente: llamar después de que /api/auth/switch activó la cuenta en la cookie. */
  activateAccount: (userId: number) => SavedAccount | null
  forgetSavedAccount: (userId: number) => void
}

function snapshotActive(state: AuthState): SavedAccount | null {
  if (!state.user) return null
  return {
    user: state.user,
    role: state.role,
    permissions: state.permissions,
    emailVerified: state.emailVerified,
    rememberMe: state.rememberMe,
    lastUsedAt: Date.now(),
  }
}

/** Guarda la cuenta activa saliente (si es otra) y excluye a la entrante. */
function savedAccountsAfterActivating(state: AuthState, incomingUserId: number): SavedAccount[] {
  const previous = snapshotActive(state)
  const saved = state.savedAccounts.filter(
    (account) => account.user.id !== incomingUserId && account.user.id !== previous?.user.id,
  )
  if (!previous || previous.user.id === incomingUserId) return saved
  return [...saved, previous]
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      role: null,
      permissions: [],
      isAuthenticated: false,
      emailVerified: false,
      isLoading: false,
      rememberMe: false,
      _hasHydrated: false,
      workspaces: [],
      activeWorkspaceId: null,
      savedAccounts: [],

      setAuth: (user, rememberMe = false, emailVerified = false, role = null, permissions = []) => {
        const savedAccounts = savedAccountsAfterActivating(get(), user.id)
        setSentryUser(user)
        set({
          user,
          role,
          permissions,
          isAuthenticated: true,
          emailVerified: emailVerified || !!user.email_verified_at,
          rememberMe,
          activeWorkspaceId: user.tenant_id ?? null,
          workspaces: user.workspaces ?? get().workspaces,
          savedAccounts,
        })
      },

      setRoleAndPermissions: (role, permissions) => {
        set({ role, permissions })
      },

      setEmailVerified: (verified) => {
        set({ emailVerified: verified })
      },

      updateUser: (userData) => {
        const current = get().user
        if (current) {
          const nextUser = { ...current, ...userData }
          setSentryUser(nextUser)
          set({ 
            user: nextUser,
            emailVerified: userData.email_verified_at ? true : get().emailVerified,
          })
        }
      },

      activateAccount: (userId) => {
        const target = get().savedAccounts.find((account) => account.user.id === userId)
        if (!target) return null

        const savedAccounts = savedAccountsAfterActivating(get(), userId)
        setSentryUser(target.user)
        set({
          user: target.user,
          role: target.role,
          permissions: target.permissions,
          isAuthenticated: true,
          emailVerified: target.emailVerified,
          rememberMe: target.rememberMe,
          workspaces: [],
          activeWorkspaceId: target.user.tenant_id ?? null,
          savedAccounts,
        })
        clearWorkspaceId()
        if (target.user.tenant_id) setWorkspaceId(target.user.tenant_id)
        return target
      },

      forgetSavedAccount: (userId) => {
        set({ savedAccounts: get().savedAccounts.filter((account) => account.user.id !== userId) })
      },

      logout: () => {
        disconnectPusher()
        clearWorkspaceId()
        setSentryUser(null)
        set({
          user: null,
          role: null,
          permissions: [],
          isAuthenticated: false,
          emailVerified: false,
          rememberMe: false,
          workspaces: [],
          activeWorkspaceId: null,
        })
      },

      setLoading: (loading) => set({ isLoading: loading }),

      setHasHydrated: (state) => {
        set({ _hasHydrated: state })
      },

      setWorkspaces: (workspaces) => set({ workspaces }),
      setActiveWorkspace: (id) => set({ activeWorkspaceId: id }),
    }),
    {
      name: "auth-storage",
      storage: createJSONStorage(() => localStorage),
      // v0 guardaba tokens bearer en localStorage; ahora viven en una cookie
      // httpOnly. Se descarta ese estado (re-login) y se reescribe sin tokens.
      version: 1,
      migrate: () => ({}),
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true)
        if (state?.user) {
          setSentryUser(state.user)
        }
      },
      partialize: (state) => ({
        user: state.user,
        role: state.role,
        permissions: state.permissions,
        isAuthenticated: state.isAuthenticated,
        emailVerified: state.emailVerified,
        rememberMe: state.rememberMe,
        savedAccounts: state.savedAccounts,
      }),
    }
  )
)
