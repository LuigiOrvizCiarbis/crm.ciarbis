import { create } from "zustand"
import { persist } from "zustand/middleware"

export type IntegrationType = "whatsapp" | "instagram" | "facebook" | "telegram" | "smtp" | "gcal" | "mp" | "stripe"
export type PlanId = "starter" | "classic" | "intermediate" | "high" | "agency" | "enterprise"
export type Role = "admin" | "vendedor"

export interface Integration {
  id: IntegrationType
  nombre: string
  descripcion: string
  conectado: boolean
  icono: string
}

export interface NotificationPrefs {
  nuevoMensaje: boolean
  tareaVencida: boolean
  tareaProxima: boolean
  cierreVenta: boolean
  recordatoriosDiarios: boolean
  reporteSemanal: boolean
}

export interface Channel {
  id: string
  tipo: "whatsapp" | "instagram" | "facebook" | "telegram" | "email"
  label: string
  handle: string
  activo: boolean
}

export interface ApiKey {
  id: string
  env: "live" | "test"
  masked: string
  createdAt: string
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

  integrations: Integration[]
  toggleIntegration: (id: IntegrationType) => void

  notifications: NotificationPrefs
  setNotifications: (prefs: Partial<NotificationPrefs>) => void

  channels: Channel[]
  addChannel: (channel: Channel) => void
  removeChannel: (id: string) => void

  apiKeys: ApiKey[]
  generateApiKey: (env: "live" | "test") => void
  revokeApiKey: (id: string) => void

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

      integrations: [
        {
          id: "whatsapp",
          nombre: "WhatsApp (Meta Cloud API)",
          descripcion: "API oficial de WhatsApp Business",
          conectado: true,
          icono: "💬",
        },
        {
          id: "instagram",
          nombre: "Instagram",
          descripcion: "Mensajes directos de Instagram",
          conectado: false,
          icono: "📸",
        },
        {
          id: "facebook",
          nombre: "Facebook",
          descripcion: "Messenger de Facebook",
          conectado: false,
          icono: "👥",
        },
        {
          id: "telegram",
          nombre: "Telegram",
          descripcion: "Bot de Telegram",
          conectado: false,
          icono: "✈️",
        },
        {
          id: "smtp",
          nombre: "Email (SMTP)",
          descripcion: "Servidor de correo electrónico",
          conectado: false,
          icono: "📧",
        },
        {
          id: "gcal",
          nombre: "Google Calendar",
          descripcion: "Sincronización de calendario",
          conectado: false,
          icono: "📅",
        },
        {
          id: "mp",
          nombre: "Mercado Pago",
          descripcion: "Pagos en Argentina",
          conectado: false,
          icono: "💳",
        },
        {
          id: "stripe",
          nombre: "Stripe",
          descripcion: "Pagos internacionales",
          conectado: false,
          icono: "💰",
        },
      ],
      toggleIntegration: (id) =>
        set((state) => ({
          integrations: state.integrations.map((int) => (int.id === id ? { ...int, conectado: !int.conectado } : int)),
        })),

      notifications: {
        nuevoMensaje: true,
        tareaVencida: true,
        tareaProxima: true,
        cierreVenta: true,
        recordatoriosDiarios: false,
        reporteSemanal: true,
      },
      setNotifications: (prefs) =>
        set((state) => ({
          notifications: { ...state.notifications, ...prefs },
        })),

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

      apiKeys: [
        {
          id: "1",
          env: "live",
          masked: "sk_live_••••••••••••4521",
          createdAt: "settings.time3months",
        },
        {
          id: "2",
          env: "test",
          masked: "sk_test_••••••••••••8932",
          createdAt: "settings.time1month",
        },
      ],
      generateApiKey: (env) =>
        set((state) => ({
          apiKeys: [
            ...state.apiKeys,
            {
              id: Date.now().toString(),
              env,
              masked: `sk_${env}_••••••••••••${Math.floor(Math.random() * 10000)}`,
              createdAt: "settings.timeNow",
            },
          ],
        })),
      revokeApiKey: (id) =>
        set((state) => ({
          apiKeys: state.apiKeys.filter((key) => key.id !== id),
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
