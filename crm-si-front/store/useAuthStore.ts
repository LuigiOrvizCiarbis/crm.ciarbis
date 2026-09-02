import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"
import * as Sentry from "@sentry/nextjs"
import { disconnectPusher } from "@/lib/pusher"
import type { NavigationLabels } from "@/data/navigation"

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

export interface User {
  id: number
  name: string
  email: string
  email_verified_at?: string | null
  tenant_id?: number
  tenant?: UserTenant | null
  branch_id?: number | null
  created_at?: string
  updated_at?: string
}

interface AuthState {
  user: User | null
  token: string | null
  role: UserRole | null
  permissions: string[]
  isAuthenticated: boolean
  emailVerified: boolean
  isLoading: boolean
  rememberMe: boolean
  _hasHydrated: boolean

  // Actions
  setAuth: (
    user: User,
    token: string,
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
  checkAuth: () => boolean
  setHasHydrated: (state: boolean) => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      role: null,
      permissions: [],
      isAuthenticated: false,
      emailVerified: false,
      isLoading: false,
      rememberMe: false,
      _hasHydrated: false,

      setAuth: (user, token, rememberMe = false, emailVerified = false, role = null, permissions = []) => {
        setSentryUser(user)
        set({
          user,
          token,
          role,
          permissions,
          isAuthenticated: true,
          emailVerified: emailVerified || !!user.email_verified_at,
          rememberMe,
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
          set({ 
            user: { ...current, ...userData },
            emailVerified: userData.email_verified_at ? true : get().emailVerified,
          })
        }
      },

      logout: () => {
        disconnectPusher()
        setSentryUser(null)
        set({
          user: null,
          token: null,
          role: null,
          permissions: [],
          isAuthenticated: false,
          emailVerified: false,
          rememberMe: false,
        })
      },

      setLoading: (loading) => set({ isLoading: loading }),

      checkAuth: () => {
        const state = get()
        return state.isAuthenticated && !!state.token
      },

      setHasHydrated: (state) => {
        set({ _hasHydrated: state })
      },
    }),
    {
      name: "auth-storage",
      storage: createJSONStorage(() => localStorage),
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true)
        if (state?.user) {
          setSentryUser(state.user)
        }
      },
      partialize: (state) => ({
        user: state.user,
        token: state.token,
        role: state.role,
        permissions: state.permissions,
        isAuthenticated: state.isAuthenticated,
        emailVerified: state.emailVerified,
        rememberMe: state.rememberMe,
      }),
    }
  )
)
