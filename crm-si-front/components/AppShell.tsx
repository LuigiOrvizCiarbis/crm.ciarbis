"use client"

import type React from "react"
import { useEffect, useRef } from "react"
import { usePathname } from "next/navigation"
import { AppSidebar } from "@/components/AppSidebar"
import { TrialBanner } from "@/components/TrialBanner"
import { useUserNotifications } from "@/hooks/useUserNotifications"
import { isRouteMatch, routesWithoutAppShell } from "@/lib/routes"

interface AppShellProps {
  children: React.ReactNode
}

export function AppShell({ children }: AppShellProps) {
  const pathname = usePathname()
  const shouldSkipShell = isRouteMatch(pathname, routesWithoutAppShell)
  const mainRef = useRef<HTMLElement | null>(null)

  useUserNotifications()

  useEffect(() => {
    if (shouldSkipShell) return

    const repaintMain = () => {
      if (document.visibilityState !== "visible") return
      const main = mainRef.current
      if (!main) return
      const prev = main.style.display
      main.style.display = "none"
      void main.offsetHeight // reflow síncrono con el main desmontado del layout
      main.style.display = prev
    }

    window.addEventListener("focus", repaintMain)
    document.addEventListener("visibilitychange", repaintMain)

    return () => {
      window.removeEventListener("focus", repaintMain)
      document.removeEventListener("visibilitychange", repaintMain)
    }
  }, [shouldSkipShell])

  if (shouldSkipShell) {
    return <>{children}</>
  }

  return (
    <div className="fixed inset-0 flex overflow-hidden bg-background">
      <AppSidebar />

      <div className="flex-1 flex min-h-0 flex-col min-w-0 bg-muted/30">
        <TrialBanner />
        <main
          ref={mainRef}
          className="flex-1 flex min-h-0 flex-col overflow-auto bg-background pb-[max(env(safe-area-inset-bottom),64px)] md:pb-0"
        >
          {children}
        </main>
      </div>
    </div>
  )
}
