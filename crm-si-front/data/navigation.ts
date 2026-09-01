import type { LucideIcon } from "lucide-react"
import {
  BarChart3,
  CheckSquare,
  Megaphone,
  MessageSquare,
  Package,
  Settings,
  Target,
  Users,
} from "lucide-react"

export const NAVIGATION_KEYS = [
  "dashboard",
  "chats",
  "contacts",
  "catalog",
  "pipeline",
  "tasks",
  "broadcasts",
  "settings",
] as const

export type NavigationKey = (typeof NAVIGATION_KEYS)[number]
export type NavigationLabels = Partial<Record<NavigationKey, string>>

export interface NavigationItem {
  key: NavigationKey
  href: string
  emoji: string
  icon: LucideIcon
  labelKey: string
}

export const NAVIGATION_ITEMS: readonly NavigationItem[] = [
  { key: "dashboard", href: "/dashboard", emoji: "📊", icon: BarChart3, labelKey: "nav.panel" },
  { key: "chats", href: "/chats", emoji: "💬", icon: MessageSquare, labelKey: "nav.chats" },
  { key: "contacts", href: "/contactos", emoji: "👥", icon: Users, labelKey: "nav.contacts" },
  { key: "catalog", href: "/catalogo", emoji: "📦", icon: Package, labelKey: "nav.catalog" },
  { key: "pipeline", href: "/oportunidades", emoji: "🎯", icon: Target, labelKey: "nav.pipeline" },
  { key: "tasks", href: "/tareas", emoji: "✅", icon: CheckSquare, labelKey: "nav.tasks" },
  { key: "broadcasts", href: "/difusiones", emoji: "📣", icon: Megaphone, labelKey: "nav.broadcasts" },
  { key: "settings", href: "/configuracion", emoji: "⚙️", icon: Settings, labelKey: "nav.settings" },
]

export function resolveNavigationLabel(
  item: NavigationItem,
  labels: NavigationLabels | null | undefined,
  t: (key: string) => string,
): string {
  return labels?.[item.key] || t(item.labelKey)
}
