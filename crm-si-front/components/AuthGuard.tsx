"use client"

import { useEffect, useState } from "react"
import { useRouter, usePathname } from "next/navigation"
import { useAuthStore } from "@/store/useAuthStore"
import { Loader2 } from "lucide-react"
import { authOnlyRoutes, isRouteMatch, publicRoutes, trialExpiredAllowedRoutes, unverifiedAllowedRoutes } from "@/lib/routes"
import { isTrialExpired } from "@/lib/trial"
import { useConfigStore } from "@/store/useConfigStore"

interface AuthGuardProps {
  children: React.ReactNode
}

export function AuthGuard({ children }: AuthGuardProps) {
  const router = useRouter()
  const pathname = usePathname()
  const { isAuthenticated, emailVerified, token, _hasHydrated, setEmailVerified, updateUser, setRoleAndPermissions } = useAuthStore()
  const [isChecking, setIsChecking] = useState(true)
  const [hasCompletedInitialCheck, setHasCompletedInitialCheck] = useState(false)

  const isPublicRoute = isRouteMatch(pathname, publicRoutes)
  const isAuthOnlyRoute = isRouteMatch(pathname, authOnlyRoutes)
  const isUnverifiedAllowed = isRouteMatch(pathname, unverifiedAllowedRoutes)
  const isTrialExpiredAllowed = isRouteMatch(pathname, trialExpiredAllowedRoutes)

  useEffect(() => {
    // Esperar a que Zustand se hidrate desde localStorage
    if (!_hasHydrated) {
      return
    }

    const checkAuth = async () => {
      if (!hasCompletedInitialCheck) {
        setIsChecking(true)
      }
      
      // Si está autenticado y trata de acceder a login/register, redirigir
      if (isAuthenticated && token && isAuthOnlyRoute) {
        if (emailVerified) {
          router.replace("/chats")
        } else {
          router.replace("/verify-email")
        }
        return
      }
      
      // Si no está autenticado y la ruta no es pública, redirigir a login
      if (!isAuthenticated && !isPublicRoute) {
        router.replace("/login")
        return
      }
      
      // Si hay token, verificar estado del usuario
      if (token && !isPublicRoute) {
        try {
          const res = await fetch("/api/auth/me", {
            headers: { "Authorization": `Bearer ${token}` },
          })
          
          if (!res.ok) {
            // Token inválido, limpiar estado y redirigir
            useAuthStore.getState().logout()
            router.replace("/login")
            return
          }

          const data = await res.json()

          // Refrescar role y permissions desde el backend en cada chequeo
          if (data.role !== undefined || data.permissions !== undefined) {
            setRoleAndPermissions(data.role ?? null, data.permissions ?? [])
          }

          // Actualizar estado de verificación de email
          const isVerified = !!data.user?.email_verified_at
          if (isVerified !== emailVerified) {
            setEmailVerified(isVerified)
          }
          updateUser(data.user)

          // El idioma sólo se aplica desde el backend en el chequeo inicial
          // de la sesión. updateUser() de arriba mergea el `user` completo
          // (incluida `preferences`) en cada navegación — si aplicáramos el
          // idioma acá siempre, un cambio hecho en /perfil quedaría revertido
          // por el /me viejo en la primera navegación posterior al guardado.
          if (!hasCompletedInitialCheck) {
            const locale = data.user?.preferences?.locale
            if (locale === "es" || locale === "en") {
              const currentLanguage = useConfigStore.getState().language
              if (currentLanguage !== locale) {
                useConfigStore.getState().setLanguage(locale)
              }
            }
          }

          // Si el email no está verificado y no está en ruta permitida, redirigir
          if (!isVerified && !isUnverifiedAllowed) {
            router.replace("/verify-email")
            return
          }

          // Si el email está verificado y está en verify-email, redirigir a chats
          if (isVerified && pathname === "/verify-email") {
            router.replace("/chats")
            return
          }

          const trialExpired = isTrialExpired(data.user?.tenant)

          // Trial vencido y no está en ruta permitida, redirigir a pantalla de bloqueo
          if (isVerified && trialExpired && !isTrialExpiredAllowed) {
            router.replace("/trial-expired")
            return
          }

          // Trial ya no está vencido (upgrade) y sigue en la pantalla de bloqueo
          if (isVerified && !trialExpired && pathname === "/trial-expired") {
            router.replace("/chats")
            return
          }
        } catch {
          // Error de red, permitir continuar
        }
      }
      
      setIsChecking(false)
      setHasCompletedInitialCheck(true)
    }
    
    checkAuth()
  }, [
    pathname,
    isAuthenticated,
    emailVerified,
    token,
    router,
    _hasHydrated,
    setEmailVerified,
    updateUser,
    setRoleAndPermissions,
    hasCompletedInitialCheck,
    isPublicRoute,
    isAuthOnlyRoute,
    isUnverifiedAllowed,
    isTrialExpiredAllowed,
  ])

  // Las rutas públicas se renderizan siempre: no dependen del estado de auth y
  // deben quedar en el HTML inicial para los crawlers.
  if (isPublicRoute) {
    return <>{children}</>
  }

  // Mostrar loader mientras se hidrata o verifica autenticación
  if (!_hasHydrated || (isChecking && !hasCompletedInitialCheck) || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-4">
          <Loader2 className="w-8 h-8 animate-spin text-primary" />
          <p className="text-sm text-muted-foreground">Cargando...</p>
        </div>
      </div>
    )
  }

  return <>{children}</>
}
