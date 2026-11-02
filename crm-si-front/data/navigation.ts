import type { LucideIcon } from "lucide-react"
import {
  BarChart3,
  CheckSquare,
  Instagram,
  Megaphone,
  MessageSquare,
  ReceiptText,
  Package,
  Settings,
  Target,
  Users,
} from "lucide-react"

export const NAVIGATION_KEYS = [
  "dashboard",
  "chats",
  "instagram_comments",
  "contacts",
  "catalog",
  "pipeline",
  "tasks",
  "broadcasts",
  "invoices",
  "settings",
] as const

export type NavigationKey = (typeof NAVIGATION_KEYS)[number]
export type NavigationLabels = Partial<Record<NavigationKey, string>>

export interface NavigationItem {
  key: NavigationKey
  href: string
  icon: LucideIcon
  labelKey: string
}

export const NAVIGATION_ITEMS: readonly NavigationItem[] = [
  { key: "dashboard", href: "/dashboard", icon: BarChart3, labelKey: "nav.panel" },
  { key: "chats", href: "/chats", icon: MessageSquare, labelKey: "nav.chats" },
  { key: "instagram_comments", href: "/comentarios-instagram", icon: Instagram, labelKey: "nav.instagramComments" },
  { key: "contacts", href: "/contactos", icon: Users, labelKey: "nav.contacts" },
  { key: "catalog", href: "/catalogo", icon: Package, labelKey: "nav.catalog" },
  { key: "pipeline", href: "/oportunidades", icon: Target, labelKey: "nav.pipeline" },
  { key: "tasks", href: "/tareas", icon: CheckSquare, labelKey: "nav.tasks" },
  { key: "broadcasts", href: "/difusiones", icon: Megaphone, labelKey: "nav.broadcasts" },
  { key: "invoices", href: "/invoices", icon: ReceiptText, labelKey: "nav.invoices" },
  { key: "settings", href: "/configuracion", icon: Settings, labelKey: "nav.settings" },
]

export function resolveNavigationLabel(
  item: NavigationItem,
  labels: NavigationLabels | null | undefined,
  t: (key: string) => string,
): string {
  return labels?.[item.key] || t(item.labelKey)
}
