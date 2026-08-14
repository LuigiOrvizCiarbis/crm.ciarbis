"use client"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet"
import { Badge } from "@/components/ui/badge"
import { getConversationUnreadCount } from "@/lib/api/conversations"
import { useAuthStore } from "@/store/useAuthStore"
import { useTaskStore } from "@/store/useTaskStore"
import { MessageSquare, Users, Target, CheckSquare, BarChart3, Menu, Settings, LogOut, Megaphone } from "lucide-react"
import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useEffect, useMemo, useRef, useState } from "react"
import { useTranslation } from "@/hooks/useTranslation"

interface MobileBottomNavProps {
  className?: string
}

export function MobileBottomNav({ className }: MobileBottomNavProps) {
  const pathname = usePathname()
  const router = useRouter()
  const { t } = useTranslation()
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isMobileViewport, setIsMobileViewport] = useState(false)
  const [unreadChats, setUnreadChats] = useState(0)
  const hasRequestedTasks = useRef(false)
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const token = useAuthStore((state) => state.token)
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

  const mainNavItems = [
    {
      href: "/dashboard",
      icon: BarChart3,
      label: "Panel",
      isActive: pathname === "/dashboard",
    },
    {
      href: "/chats",
      icon: MessageSquare,
      label: "Chats",
      isActive: pathname === "/chats",
      badge: unreadChats,
    },
    {
      href: "/contactos",
      icon: Users,
      label: "Contactos",
      isActive: pathname === "/contactos",
    },
    {
      href: "/oportunidades",
      icon: Target,
      label: "Pipeline",
      isActive: pathname === "/oportunidades",
    },
    {
      href: "/tareas",
      icon: CheckSquare,
      label: "Tareas",
      isActive: pathname === "/tareas",
      badge: pendingTasks,
    },
  ]

  const menuItems: { href: string; icon: typeof Settings; label: string; onClick?: () => void }[] = [
    {
      href: "/difusiones",
      icon: Megaphone,
      label: "Difusiones",
    },
    {
      href: "/configuracion",
      icon: Settings,
      label: "Configuración",
    },
  ]

  return (
    <div
      className={cn(
        "fixed bottom-0 left-0 right-0 z-50 bg-background border-t border-border md:hidden",
        "pb-[max(env(safe-area-inset-bottom),0px)]",
        className,
      )}
    >
      <div className="flex items-center justify-around px-2 py-2">
        {mainNavItems.map((item) => (
          <Link key={item.href} href={item.href} className="flex-1">
            <Button
              variant="ghost"
              size="sm"
              className={cn(
                "flex flex-col items-center gap-1 h-auto py-2 px-1 relative min-h-11",
                item.isActive ? "text-primary bg-primary/10" : "text-muted-foreground hover:text-foreground",
              )}
            >
              <item.icon className="w-5 h-5" />
              <span className="text-xs font-medium">{item.label}</span>
              {item.badge ? (
                <Badge
                  variant="destructive"
                  className="absolute -top-1 -right-1 h-5 min-w-5 px-1 text-xs flex items-center justify-center"
                >
                  {formatBadgeCount(item.badge)}
                </Badge>
              ) : null}
            </Button>
          </Link>
        ))}

        <Sheet open={isMenuOpen} onOpenChange={setIsMenuOpen}>
          <SheetTrigger asChild>
            <Button
              variant="ghost"
              size="sm"
              className="flex flex-col items-center gap-1 h-auto py-2 px-1 text-muted-foreground hover:text-foreground min-h-11"
            >
              <Menu className="w-5 h-5" />
              <span className="text-xs font-medium">Menú</span>
            </Button>
          </SheetTrigger>
          <SheetContent side="bottom" className="h-auto">
            <div className="py-4">
              <SheetHeader className="px-0 pt-0">
                <SheetTitle>Menú</SheetTitle>
              </SheetHeader>
              <div className="grid grid-cols-2 gap-2">
                {menuItems.map((item) => (
                  <Link
                    key={item.href}
                    href={item.href}
                    onClick={() => {
                      if (item.onClick) {
                        item.onClick()
                      }
                      setIsMenuOpen(false)
                    }}
                  >
                    <Button variant="outline" className="w-full justify-start gap-3 h-12 bg-transparent">
                      <item.icon className="w-5 h-5" />
                      {item.label}
                    </Button>
                  </Link>
                ))}
                <Button
                  variant="outline"
                  onClick={handleLogout}
                  className="w-full justify-start gap-3 h-12 bg-transparent text-destructive hover:text-destructive hover:bg-destructive/10 col-span-2"
                >
                  <LogOut className="w-5 h-5" />
                  {t("nav.logout")}
                </Button>
              </div>
            </div>
          </SheetContent>
        </Sheet>
      </div>
    </div>
  )
}
