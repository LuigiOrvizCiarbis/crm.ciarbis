"use client"

import { cn } from "@/lib/utils"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet"
import { Badge } from "@/components/ui/badge"
import { getConversationUnreadCount } from "@/lib/api/conversations"
import { useAuthStore } from "@/store/useAuthStore"
import { useTaskStore } from "@/store/useTaskStore"
import { Bell, Menu, LogOut, User } from "lucide-react"
import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useEffect, useMemo, useRef, useState } from "react"
import { useTranslation } from "@/hooks/useTranslation"
import { resolveNavigationLabel, type NavigationKey } from "@/data/navigation"
import { accessibleNavigationItems } from "@/lib/section-access"
import { NotificationsPanel } from "@/components/notifications/NotificationsPanel"
import { useNotificationsStore } from "@/store/useNotificationsStore"

interface MobileBottomNavProps {
  className?: string
}

// Cuatro destinos fijos en la barra (los que más usa un vendedor) y el resto en "Más".
// Si el rol no tiene alguno, el siguiente de la lista ocupa su lugar.
const PRIMARY_TAB_ORDER: readonly NavigationKey[] = ["chats", "contacts", "pipeline", "tasks", "dashboard"]
const PRIMARY_TAB_LIMIT = 4

const SHORT_LABEL_KEYS: Partial<Record<NavigationKey, string>> = {
  dashboard: "nav.panelShort",
  chats: "nav.chatsShort",
  contacts: "nav.contactsShort",
  catalog: "nav.catalogShort",
  pipeline: "nav.pipelineShort",
  tasks: "nav.tasksShort",
  settings: "nav.settingsShort",
}

const TAB_CLASS =
  "flex min-h-11 min-w-0 flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1 outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50"
const TAB_ACTIVE_CLASS = "bg-primary/10 text-primary"
const TAB_IDLE_CLASS = "text-muted-foreground hover:text-foreground"
const TAB_BADGE_CLASS =
  "absolute -top-1.5 left-3 h-5 min-w-5 rounded-full px-1.5 tabular-nums"

const SHEET_ITEM_CLASS =
  "flex h-12 min-w-0 items-center gap-3 rounded-md border px-3 text-sm font-medium outline-none transition-colors focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
const SHEET_ITEM_ACTIVE_CLASS = "border-primary/30 bg-primary/10 text-primary"
const SHEET_ITEM_IDLE_CLASS = "border-border text-foreground hover:bg-accent"

export function MobileBottomNav({ className }: MobileBottomNavProps) {
  const pathname = usePathname()
  const router = useRouter()
  const { t } = useTranslation()
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isNotificationsOpen, setIsNotificationsOpen] = useState(false)
  const unreadNotifications = useNotificationsStore((state) => state.unreadCount)
  const [isMobileViewport, setIsMobileViewport] = useState(false)
  const [unreadChats, setUnreadChats] = useState(0)
  const hasRequestedTasks = useRef(false)
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const token = useAuthStore((state) => state.token)
  const navigationLabels = useAuthStore((state) => state.user?.tenant?.navigation_labels)
  const permissions = useAuthStore((state) => state.permissions)
  const role = useAuthStore((state) => state.role)
  const logoutStore = useAuthStore((state) => state.logout)
  const tasks = useTaskStore((state) => state.tasks)
  const isTasksLoading = useTaskStore((state) => state.isLoading)
  const fetchTasks = useTaskStore((state) => state.fetchTasks)

  const handleLogout = async () => {
    try {
      await fetch("/api/auth/logout", {
        method: "POST",
        headers: { "Authorization": `Bearer ${token}` },
      })
    } catch {
      // Ignorar errores, cerrar sesión de todos modos
    }
    logoutStore()
    setIsMenuOpen(false)
    router.replace("/login")
  }

  useEffect(() => {
    const mediaQuery = window.matchMedia("(max-width: 767px)")
    const updateViewport = () => setIsMobileViewport(mediaQuery.matches)

    updateViewport()
    mediaQuery.addEventListener("change", updateViewport)

    return () => {
      mediaQuery.removeEventListener("change", updateViewport)
    }
  }, [])

  useEffect(() => {
    if (!isAuthenticated || !isMobileViewport) {
      setUnreadChats(0)
      return
    }

    let cancelled = false

    const refresh = () => {
      getConversationUnreadCount()
        .then((unreadCount) => {
          if (cancelled) return
          setUnreadChats(unreadCount)
        })
        .catch(() => {
          if (!cancelled) setUnreadChats(0)
        })
    }

    refresh()

    const handler = () => refresh()
    window.addEventListener("conversations:unread-changed", handler)

    return () => {
      cancelled = true
      window.removeEventListener("conversations:unread-changed", handler)
    }
  }, [isAuthenticated, isMobileViewport])

  useEffect(() => {
    if (!isAuthenticated || !isMobileViewport) {
      hasRequestedTasks.current = false
      return
    }

    if (isTasksLoading || tasks.length > 0 || hasRequestedTasks.current) return

    hasRequestedTasks.current = true

    fetchTasks().catch(() => {
      // Task fetch errors are handled in the task store; the nav simply hides the badge.
    })
  }, [fetchTasks, isAuthenticated, isMobileViewport, isTasksLoading, tasks.length])

  const pendingTasks = useMemo(() => {
    return tasks.filter((task) => task.status !== "hecho" && task.status !== "cancelado").length
  }, [tasks])

  const formatBadgeCount = (count: number) => (count > 99 ? "99+" : String(count))

  const isRouteActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`)

  const accessibleItems = accessibleNavigationItems(permissions, role)
  const primaryKeys = PRIMARY_TAB_ORDER.filter((key) => accessibleItems.some((item) => item.key === key)).slice(0, PRIMARY_TAB_LIMIT)

  const primaryTabs = primaryKeys.map((key) => {
    const item = accessibleItems.find((candidate) => candidate.key === key)!
    const label = resolveNavigationLabel(item, navigationLabels, t)
    const shortLabelKey = SHORT_LABEL_KEYS[item.key]
    // Una etiqueta personalizada del tenant manda; si no hay, la versión corta entra en una sola línea.
    const shortLabel = navigationLabels?.[item.key] || (shortLabelKey ? t(shortLabelKey) : label)
    return {
      ...item,
      label,
      shortLabel,
      isActive: isRouteActive(item.href),
      badge: item.key === "chats" ? unreadChats : item.key === "tasks" ? pendingTasks : 0,
    }
  })

  const moreItems = accessibleItems
    .filter((item) => !primaryKeys.includes(item.key))
    .map((item) => ({
      ...item,
      label: resolveNavigationLabel(item, navigationLabels, t),
      isActive: isRouteActive(item.href),
    }))

  const isProfileActive = isRouteActive("/perfil")
  const isMoreActive = isMenuOpen || isProfileActive || moreItems.some((item) => item.isActive)

  return (
    <nav
      aria-label={t("nav.mobileNavigation")}
      className={cn(
        "fixed bottom-0 left-0 right-0 z-50 bg-background border-t border-border md:hidden",
        "pb-[max(env(safe-area-inset-bottom),0px)]",
        className,
      )}
    >
      <div className="grid grid-flow-col auto-cols-fr gap-1 px-1 py-1">
        {primaryTabs.map((item) => (
          <Link
            key={item.href}
            href={item.href}
            aria-current={item.isActive ? "page" : undefined}
            title={item.label}
            className={cn(TAB_CLASS, item.isActive ? TAB_ACTIVE_CLASS : TAB_IDLE_CLASS)}
          >
            <span className="relative">
              <item.icon className="size-5" aria-hidden="true" />
              {item.badge ? (
                <Badge variant="destructive" className={TAB_BADGE_CLASS}>
                  {formatBadgeCount(item.badge)}
                </Badge>
              ) : null}
            </span>
            <span className="max-w-full truncate text-xs font-medium leading-4" aria-hidden={item.shortLabel !== item.label}>
              {item.shortLabel}
            </span>
            {item.shortLabel !== item.label ? <span className="sr-only">{item.label}</span> : null}
          </Link>
        ))}

        <Sheet open={isMenuOpen} onOpenChange={setIsMenuOpen}>
          <SheetTrigger asChild>
            <button
              type="button"
              className={cn(TAB_CLASS, isMoreActive ? TAB_ACTIVE_CLASS : TAB_IDLE_CLASS)}
            >
              <span className="relative">
                <Menu className="size-5" aria-hidden="true" />
                {unreadNotifications ? (
                  <Badge variant="destructive" className={TAB_BADGE_CLASS}>
                    {formatBadgeCount(unreadNotifications)}
                  </Badge>
                ) : null}
              </span>
              <span className="max-w-full truncate text-xs font-medium leading-4">{t("nav.more")}</span>
            </button>
          </SheetTrigger>
          <SheetContent
            side="bottom"
            className="h-auto max-h-[85dvh] gap-0 overflow-y-auto rounded-t-xl px-4 pt-4 pb-[max(env(safe-area-inset-bottom),1rem)]"
          >
            <SheetHeader className="px-0 pt-0 pb-3">
              <SheetTitle>{t("nav.more")}</SheetTitle>
            </SheetHeader>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                className={cn(SHEET_ITEM_CLASS, SHEET_ITEM_IDLE_CLASS, "col-span-2")}
                onClick={() => {
                  setIsMenuOpen(false)
                  setIsNotificationsOpen(true)
                }}
              >
                <Bell className="size-5 shrink-0" aria-hidden="true" />
                <span className="truncate">{t("notifications.title")}</span>
                {unreadNotifications ? (
                  <Badge variant="destructive" className="ml-auto h-5 min-w-5 rounded-full px-1.5 tabular-nums">
                    {formatBadgeCount(unreadNotifications)}
                  </Badge>
                ) : null}
              </button>
              {moreItems.map((item) => (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={item.isActive ? "page" : undefined}
                  title={item.label}
                  onClick={() => setIsMenuOpen(false)}
                  className={cn(SHEET_ITEM_CLASS, item.isActive ? SHEET_ITEM_ACTIVE_CLASS : SHEET_ITEM_IDLE_CLASS)}
                >
                  <item.icon className="size-5 shrink-0" aria-hidden="true" />
                  <span className="truncate">{item.label}</span>
                </Link>
              ))}
              <Link
                href="/perfil"
                aria-current={isProfileActive ? "page" : undefined}
                title={t("nav.profile")}
                onClick={() => setIsMenuOpen(false)}
                className={cn(SHEET_ITEM_CLASS, isProfileActive ? SHEET_ITEM_ACTIVE_CLASS : SHEET_ITEM_IDLE_CLASS)}
              >
                <User className="size-5 shrink-0" aria-hidden="true" />
                <span className="truncate">{t("nav.profile")}</span>
              </Link>
              <button
                type="button"
                onClick={handleLogout}
                className={cn(SHEET_ITEM_CLASS, "col-span-2 border-border text-destructive hover:bg-destructive/10")}
              >
                <LogOut className="size-5 shrink-0" aria-hidden="true" />
                <span className="truncate">{t("nav.logout")}</span>
              </button>
            </div>
          </SheetContent>
        </Sheet>

        <Sheet open={isNotificationsOpen} onOpenChange={setIsNotificationsOpen}>
          <SheetContent side="bottom" className="h-auto max-h-[85dvh] gap-0 p-0">
            <SheetHeader className="sr-only">
              <SheetTitle>{t("notifications.title")}</SheetTitle>
            </SheetHeader>
            <NotificationsPanel onNavigate={() => setIsNotificationsOpen(false)} headerClassName="pr-12" className="pb-[max(env(safe-area-inset-bottom),0px)]" />
          </SheetContent>
        </Sheet>
      </div>
    </nav>
  )
}
