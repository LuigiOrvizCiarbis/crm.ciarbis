"use client"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { ChevronDown, ChevronLeft, ChevronRight, LogOut, Menu } from "lucide-react"
import Image from "next/image"
import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { ThemeToggle } from "@/components/theme-toggle"
import { useState, useEffect } from "react"
import { useAuthStore } from "@/store/useAuthStore"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { useTranslation } from "@/hooks/useTranslation"
import { LanguageSwitcher } from "@/components/LanguageSwitcher"
import { NAVIGATION_ITEMS, resolveNavigationLabel } from "@/data/navigation"

interface SidebarProps {
  className?: string
  isCollapsed?: boolean
  onToggle?: () => void
}

export function CrmSidebar({ className, isCollapsed = false, onToggle }: SidebarProps) {
  const showAutomationSection = false
  const pathname = usePathname()
  const router = useRouter()
  const [openSections, setOpenSections] = useState<string[]>([])
  const { user, token, logout } = useAuthStore()
  const { t } = useTranslation()

  const handleLogout = async () => {
    try {
      await fetch("/api/auth/logout", {
        method: "POST",
        headers: { "Authorization": `Bearer ${token}` },
      })
    } catch {
      // Ignorar errores, cerrar sesión de todos modos
    }
    logout()
    router.replace("/login")
  }

  const userInitials = user?.name
    ? user.name.split(" ").map(n => n[0]).join("").toUpperCase().slice(0, 2)
    : user?.email?.slice(0, 2).toUpperCase() || "U"

  const toggleSection = (section: string) => {
    if (isCollapsed) return
    setOpenSections((prev) => (prev.includes(section) ? prev.filter((s) => s !== section) : [...prev, section]))
  }

  const navItems = NAVIGATION_ITEMS.filter((item) => item.key !== "settings").flatMap((item) => [
    item,
    ...(item.key === "chats" ? [{
      href: "/comentarios-instagram",
      emoji: "📸",
      label: "Comentarios IG",
    }] : []),
  ])

  const automationItems = [
    {
      href: "/asistente-ia",
      emoji: "✨",
      label: "Atlas IA",
    },
    {
      href: "/chatbot",
      emoji: "🤖",
      label: "Chatbot IA",
    },
    {
      href: "/remarketing",
      emoji: "📧",
      label: "Remarketing",
    },
    {
      href: "/workflows",
      emoji: "⚡",
      label: "Workflows",
    },
    {
      href: "/asignacion-ia",
      emoji: "🎲",
      label: "Asignación IA",
    },
    {
      href: "/analytics-ia",
      emoji: "📈",
      label: "Analytics IA",
    },
  ]

  const bottomItems = NAVIGATION_ITEMS.filter((item) => item.key === "settings")

  const isAutomationActive = automationItems.some((item) => pathname === item.href)

  useEffect(() => {
    if (isAutomationActive && !isCollapsed) {
      setOpenSections((prev) => (prev.includes("automatizacion") ? prev : [...prev, "automatizacion"]))
    } else if (isCollapsed) {
      setOpenSections([])
    }
  }, [isAutomationActive, isCollapsed])

  return (
    <div
      className={cn(
        "flex h-dvh max-h-dvh min-h-0 flex-col overflow-hidden bg-sidebar border-r border-sidebar-border transition-all duration-300",
        isCollapsed ? "w-20" : "w-60",
        className,
      )}
    >
      <div
        className={cn(
          "flex items-center border-b border-sidebar-border",
          isCollapsed ? "p-3 justify-center" : "p-4 gap-2",
        )}
      >
        {!isCollapsed && (
          <div className="flex-1">
            <Image
              src="/logo_bims.jpg"
              alt="BIMS"
              width={160}
              height={48}
              className="h-8 w-auto object-contain"
              priority
            />
          </div>
        )}
        <Button variant="ghost" size="sm" onClick={onToggle} className="p-2 hover:bg-sidebar-accent">
          {isCollapsed ? <Menu className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
        </Button>
      </div>

      {!isCollapsed && user?.tenant?.name && (
        <div className="px-4 py-3 border-b border-sidebar-border">
          <div className="w-full flex items-center gap-3 rounded-md p-2">
            <Avatar className="h-9 w-9">
              <AvatarFallback className="bg-linear-to-br from-blue-500 to-violet-500 text-white text-xs font-semibold">
                {user.tenant.name
                  .split(" ")
                  .map((part) => part[0])
                  .join("")
                  .toUpperCase()
                  .slice(0, 2)}
              </AvatarFallback>
            </Avatar>
            <div className="flex-1 min-w-0 text-left">
              <p className="text-sm font-medium text-sidebar-foreground truncate">
                {user.tenant.name}
              </p>
              <p className="text-xs text-muted-foreground truncate">
                {t("tenant.activeClient")}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Navigation */}
      <nav
        className={cn(
          "min-h-0 flex-1 space-y-2 overflow-y-auto",
          isCollapsed ? "p-2" : "p-4",
        )}
      >
        {/* Main navigation items */}
        {navItems.map((item) => {
          const isActive = pathname === item.href
          const label = "label" in item
            ? item.label
            : resolveNavigationLabel(item, user?.tenant?.navigation_labels, t)

          return (
            <Link key={item.href} href={item.href}>
              <Button
                variant="ghost"
                className={cn(
                  isCollapsed
                    ? "w-full h-auto py-2.5 px-1 flex-col gap-1 justify-center"
                    : "w-full justify-start gap-3",
                  isActive
                    ? "bg-sidebar-accent text-sidebar-accent-foreground"
                    : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
                )}
                title={label}
              >
                <span className={cn(isCollapsed ? "text-xl leading-none" : "text-base")}>{item.emoji}</span>
                {isCollapsed ? (
                  <span className="w-full truncate text-[10px] font-medium leading-tight text-center">
                    {label}
                  </span>
                ) : (
                  <span className="truncate">{label}</span>
                )}
              </Button>
            </Link>
          )
        })}

        {showAutomationSection && (
          <div>
            <Button
              variant="ghost"
              onClick={() => toggleSection("automatizacion")}
              className={cn(
                isCollapsed
                  ? "w-full h-auto py-2.5 px-1 flex-col gap-1 justify-center"
                  : "w-full justify-start gap-3",
                isAutomationActive
                  ? "bg-sidebar-accent text-sidebar-accent-foreground"
                  : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
              )}
              title={isCollapsed ? t("nav.automation") : undefined}
            >
              <span className={cn(isCollapsed ? "text-xl leading-none" : "text-base")}>🚀</span>
              {isCollapsed ? (
                <span className="text-[10px] font-medium leading-tight text-center">
                  {t("nav.automationShort")}
                </span>
              ) : (
                <>
                  {t("nav.automation")}
                  {openSections.includes("automatizacion") ? (
                    <ChevronDown className="w-4 h-4 ml-auto" />
                  ) : (
                    <ChevronRight className="w-4 h-4 ml-auto" />
                  )}
                </>
              )}
            </Button>

            {!isCollapsed && openSections.includes("automatizacion") && (
              <div className="ml-4 mt-1 space-y-1">
                {automationItems.map((item) => {
                  const isActive = pathname === item.href

                  return (
                    <Link key={item.href} href={item.href}>
                      <Button
                        variant="ghost"
                        size="sm"
                        className={cn(
                          "w-full justify-start gap-3",
                          isActive
                            ? "bg-sidebar-accent text-sidebar-accent-foreground"
                            : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
                        )}
                      >
                        <span className="text-sm">{item.emoji}</span>
                        {item.label}
                      </Button>
                    </Link>
                  )
                })}
              </div>
            )}
          </div>
        )}

        {/* Bottom items */}
        {bottomItems.map((item) => {
          const isActive = pathname === item.href
          const label = resolveNavigationLabel(item, user?.tenant?.navigation_labels, t)

          return (
            <Link key={item.href} href={item.href}>
              <Button
                variant="ghost"
                className={cn(
                  isCollapsed
                    ? "w-full h-auto py-2.5 px-1 flex-col gap-1 justify-center"
                    : "w-full justify-start gap-3",
                  isActive
                    ? "bg-sidebar-accent text-sidebar-accent-foreground"
                    : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
                )}
                title={label}
              >
                <span className={cn(isCollapsed ? "text-xl leading-none" : "text-base")}>{item.emoji}</span>
                {isCollapsed ? (
                  <span className="w-full truncate text-[10px] font-medium leading-tight text-center">
                    {label}
                  </span>
                ) : (
                  <span className="truncate">{label}</span>
                )}
              </Button>
            </Link>
          )
        })}
      </nav>

      {/* Footer */}
      {!isCollapsed && (
        <div className="mt-auto shrink-0 space-y-3 border-t border-sidebar-border p-4">
          {/* User info + Logout */}
          <div className="flex items-center gap-3">
            <Avatar className="h-8 w-8">
              <AvatarFallback className="bg-primary/10 text-primary text-xs font-medium">
                {userInitials}
              </AvatarFallback>
            </Avatar>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-sidebar-foreground truncate">
                {user?.name || "Usuario"}
              </p>
              <p className="text-xs text-muted-foreground truncate">
                {user?.email || ""}
              </p>
            </div>
            <TooltipProvider>
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={handleLogout}
                    className="h-8 w-8 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                  >
                    <LogOut className="h-4 w-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="top">
                  <p>{t("nav.logout")}</p>
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          </div>

          <div className="flex items-center justify-between">
            <LanguageSwitcher />
            <div className="flex items-center gap-2">
              <p className="text-xs text-muted-foreground">V4.4.6</p>
              <ThemeToggle />
            </div>
          </div>
        </div>
      )}

      {isCollapsed && (
        <div className="mt-auto shrink-0 space-y-2 border-t border-sidebar-border p-2">
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={handleLogout}
                  className="w-full h-10 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                >
                  <LogOut className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="right">
                <p>{t("nav.logout")}</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
          <div className="flex justify-center">
            <ThemeToggle />
          </div>
        </div>
      )}
    </div>
  )
}
