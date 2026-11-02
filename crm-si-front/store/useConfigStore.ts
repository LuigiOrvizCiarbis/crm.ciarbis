import { create } from "zustand"
import { persist } from "zustand/middleware"

export type PlanId = "starter" | "classic" | "intermediate" | "high" | "agency" | "enterprise"
export type Role = "admin" | "vendedor"

export interface Channel {
  id: string
  tipo: "whatsapp" | "instagram" | "facebook" | "telegram" | "email"
  label: string
  handle: string
  activo: boolean
}

export interface Billing {
  actual: PlanId
  vencimiento: string
  montoUSD: number
}

export interface UserWithRole {
  id: string
  nombre: string
  email: string
  role: Role
}

interface ConfigStore {
  language: "es" | "en"
  setLanguage: (language: "es" | "en") => void

  channels: Channel[]
  addChannel: (channel: Channel) => void
  removeChannel: (id: string) => void

  billing: Billing
  setBilling: (billing: Partial<Billing>) => void

  users: UserWithRole[]
  updateUserRole: (userId: string, role: Role) => void
}

export const useConfigStore = create<ConfigStore>()(
  persist(
    (set, get) => ({
      language: "es" as "es" | "en",
      setLanguage: (language: "es" | "en") => set({ language }),

      channels: [
        {
          id: "1",
          tipo: "whatsapp",
          label: "WhatsApp Account 1",
          handle: "+54 11 1234-5678",
          activo: true,
        },
        {
          id: "2",
          tipo: "whatsapp",
          label: "WhatsApp Account 2",
          handle: "+54 11 8765-4321",
          activo: true,
        },
        {
          id: "3",
          tipo: "instagram",
          label: "Instagram @socialimpulse",
          handle: "@socialimpulse",
          activo: true,
        },
      ],
      addChannel: (channel) =>
        set((state) => ({
          channels: [...state.channels, channel],
        })),
      removeChannel: (id) =>
        set((state) => ({
          channels: state.channels.filter((c) => c.id !== id),
        })),

      billing: {
        actual: "intermediate",
        vencimiento: "settings.billingDate",
        montoUSD: 250,
      },
      setBilling: (billing) =>
        set((state) => ({
          billing: { ...state.billing, ...billing },
        })),

      users: [
        {
          id: "1",
          nombre: "Juan Pérez",
          email: "juan@empresa.com",
          role: "admin",
        },
        {
          id: "2",
          nombre: "María González",
          email: "maria@empresa.com",
          role: "vendedor",
        },
      ],
      updateUserRole: (userId, role) =>
        set((state) => ({
          users: state.users.map((user) => (user.id === userId ? { ...user, role } : user)),
        })),
    }),
    {
      name: "config-store",
    },
  ),
)
