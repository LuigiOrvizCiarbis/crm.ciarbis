"use client"

import type React from "react"
import { GeistSans } from "geist/font/sans"
import { GeistMono } from "geist/font/mono"
import { Suspense } from "react"
import { usePathname } from "next/navigation"
import { ThemeProvider } from "@/components/theme-provider"
import { CommandPalette } from "@/components/CommandPalette"
import { MobileBottomNav } from "@/components/MobileBottomNav"
import { useToast } from "@/components/Toast"
import { AuthGuard } from "@/components/AuthGuard"
import { AppShell } from "@/components/AppShell"
import { isRouteMatch, routesWithoutAppShell } from "@/lib/routes"
import { SectionAccessGuard } from "@/components/auth/SectionAccessGuard"
import "./globals.css"

function ToastProvider({ children }: { children: React.ReactNode }) {
  const { ToastContainer } = useToast()

  return (
    <>
      {children}
      <ToastContainer />
    </>
  )
}

export default function ClientLayout({
  children,
}: Readonly<{
  children: React.ReactNode
}>) {
  const pathname = usePathname()
  const shouldSkipShell = isRouteMatch(pathname, routesWithoutAppShell)

  return (
    <html lang="es" suppressHydrationWarning>
      <head>
        <link rel="manifest" href="/manifest.json" />
        <meta name="theme-color" content="#0F1117" />
        <link rel="apple-touch-icon" href="/icon-192x192.png" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
      </head>
      <body className={`font-sans ${GeistSans.variable} ${GeistMono.variable}`}>
        <ThemeProvider attribute="class" defaultTheme="dark" enableSystem disableTransitionOnChange>
          <ToastProvider>
            <AuthGuard>
              <AppShell>
                <SectionAccessGuard>
                  <Suspense fallback={null}>{children}</Suspense>
                </SectionAccessGuard>
              </AppShell>
              {!shouldSkipShell ? (
                <>
                  <CommandPalette />
                  <MobileBottomNav />
                </>
              ) : null}
            </AuthGuard>
          </ToastProvider>
        </ThemeProvider>
      </body>
    </html>
  )
}
