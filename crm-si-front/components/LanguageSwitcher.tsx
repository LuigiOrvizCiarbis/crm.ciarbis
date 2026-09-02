"use client"

import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"
import { updatePreferences } from "@/lib/api/profile"
import { DEFAULT_PREFERENCES } from "@/lib/preferences"
import { useToast } from "@/components/Toast"

export function LanguageSwitcher({ className }: { className?: string }) {
  const { t, language, changeLanguage } = useTranslation()
  const { user, updateUser } = useAuthStore()
  const { addToast } = useToast()

  const toggle = async (lang: "es" | "en") => {
    // Sin sesión (login/register) el idioma es sólo una preferencia local del
    // navegador: no hay perfil de usuario al que asociarla, así que no se
    // intenta persistir ni se puede fallar por "no autenticado".
    if (!user) {
      changeLanguage(lang)
      return
    }

    const previousLocale = language

    // Optimista: el idioma cambia en la UI de inmediato; el guardado en el
    // backend corre en paralelo. AuthGuard sólo vuelve a aplicar el idioma
    // del backend en el chequeo inicial de sesión, así que esta escritura
    // local no se pisa en la siguiente navegación (ver AuthGuard.tsx). Si el
    // guardado falla, se revierte acá abajo en vez de dejar la UI y el store
    // mostrando un idioma que el backend nunca llegó a persistir.
    changeLanguage(lang)

    const current = user.preferences ?? DEFAULT_PREFERENCES
    const next = { ...current, locale: lang }
    updateUser({ preferences: next })

    const result = await updatePreferences(next)
    if (result.error) {
      changeLanguage(previousLocale)
      updateUser({ preferences: current })
      addToast({ type: "error", title: t("common.error"), description: result.error })
    }
  }

  return (
    <div className={cn("flex items-center gap-0.5 text-xs font-medium", className)}>
      <button
        onClick={() => toggle("es")}
        className={cn(
          "px-2 py-0.5 rounded transition-colors",
          language === "es"
            ? "bg-primary text-primary-foreground"
            : "text-muted-foreground hover:text-foreground"
        )}
      >
        ES
      </button>
      <span className="text-muted-foreground/50">|</span>
      <button
        onClick={() => toggle("en")}
        className={cn(
          "px-2 py-0.5 rounded transition-colors",
          language === "en"
            ? "bg-primary text-primary-foreground"
            : "text-muted-foreground hover:text-foreground"
        )}
      >
        EN
      </button>
    </div>
  )
}
