"use client"

import type React from "react"
import { GeistSans } from "geist/font/sans"
import { GeistMono } from "geist/font/mono"
import { Manrope } from "next/font/google"
import { Suspense } from "react"
import { usePathname } from "next/navigation"
import { ThemeProvider } from "@/components/theme-provider"
import { CommandPalette } from "@/components/CommandPalette"
import { MobileBottomNav } from "@/components/MobileBottomNav"
import { Toaster } from "@/components/Toast"
import { AuthGuard } from "@/components/AuthGuard"
import { AppShell } from "@/components/AppShell"
import { isRouteMatch, routesWithoutAppShell } from "@/lib/routes"
import { SectionAccessGuard } from "@/components/auth/SectionAccessGuard"
import "./globals.css"

// Tipografía de la identidad BIMS. Se carga siempre (una variable, swap) y sólo se aplica donde
// el piloto la activa; ver --font-app-sans en globals.css.
const manrope = Manrope({ subsets: ["latin"], variable: "--font-manrope", display: "swap" })

export default function ClientLayout({
  children,
}: Readonly<{
  children: React.ReactNode
}>) {
  const pathname = usePathname()
  const shouldSkipShell = isRouteMatch(pathname, routesWithoutAppShell)

  return (
    <html lang="es" suppressHydrationWarning className={`${GeistSans.variable} ${GeistMono.variable} ${manrope.variable}`}>
      <head>
        <link rel="manifest" href="/manifest.json" />
        <meta name="theme-color" content="#0F1117" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
      </head>
      <body className="font-sans">
        <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
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
          <Toaster />
        </ThemeProvider>
      </body>
    </html>
  )
}
