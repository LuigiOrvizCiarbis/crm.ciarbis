import type { ComponentType } from "react"
import type { LucideIcon } from "lucide-react"
import { Bot, CalendarClock, ShoppingBag, Webhook } from "lucide-react"

import { AiAssistantSettings } from "@/components/config/AiAssistantSettings"
import { GoogleCalendarConnectionCard } from "@/components/config/GoogleCalendarConnectionCard"
import { WebhooksSettings } from "@/components/config/WebhooksSettings"
import { WooCommerceSettings } from "@/components/config/WooCommerceSettings"
import { getAiConfig } from "@/lib/api/ai-config"
import { getGoogleCalendarConnection } from "@/lib/api/google-calendar"
import { listWebhookEndpoints } from "@/lib/api/webhooks"
import { getWooConfig } from "@/lib/api/woocommerce"

export type IntegrationStatus =
  | "connected"
  | "available"
  | "error"
  | "comingSoon"

export type IntegrationCategory = "ai" | "ecommerce" | "developer"

export interface IntegrationDefinition {
  id: string
  icon: LucideIcon
  nameKey: string
  descriptionKey: string
  category: IntegrationCategory
  Detail: ComponentType
  fetchStatus: () => Promise<IntegrationStatus>
  /** Integraciones a nivel tenant (credenciales compartidas): solo admins las gestionan.
   * Las que son personales por usuario (ej. Google Calendar) quedan visibles para todos. */
  adminOnly?: boolean
}

// Agregar una integración = agregar una entrada acá. El directorio, el detalle
// y el deep-link por hash (/configuracion/integraciones#<id>) salen de esta lista.
export const INTEGRATIONS: IntegrationDefinition[] = [
  {
    id: "ai-assistant",
    icon: Bot,
    nameKey: "settings.aiAssistant.title",
    descriptionKey: "settings.aiAssistant.description",
    category: "ai",
    adminOnly: true,
    Detail: AiAssistantSettings,
    fetchStatus: async () => {
      const config = await getAiConfig()
      return config?.has_api_key ? "connected" : "available"
    },
  },
  {
    id: "google-calendar",
    icon: CalendarClock,
    nameKey: "settings.googleCalendar.title",
    descriptionKey: "settings.googleCalendar.description",
    category: "developer",
    Detail: GoogleCalendarConnectionCard,
    fetchStatus: async () => {
      const connection = await getGoogleCalendarConnection()
      if (!connection) return "available"
      return connection.status === "needs_reauth" ? "error" : "connected"
    },
  },
  {
    id: "woocommerce",
    icon: ShoppingBag,
    nameKey: "settings.woocommerce.title",
    descriptionKey: "settings.woocommerce.description",
    category: "ecommerce",
    adminOnly: true,
    Detail: WooCommerceSettings,
    fetchStatus: async () => {
      const config = await getWooConfig()
      return config?.has_credentials ? "connected" : "available"
    },
  },
  {
    id: "webhooks",
    icon: Webhook,
    nameKey: "settings.webhooks.title",
    descriptionKey: "settings.webhooks.description",
    category: "developer",
    adminOnly: true,
    Detail: WebhooksSettings,
    fetchStatus: async () => {
      const endpoints = await listWebhookEndpoints()
      return endpoints.length > 0 ? "connected" : "available"
    },
  },
]
