"use client"

import { useMemo, useState } from "react"
import { Loader2, SlidersHorizontal } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { updatePreferences } from "@/lib/api/profile"
import { DEFAULT_PREFERENCES } from "@/lib/preferences"

const TIMEZONES = [
  "America/Argentina/Buenos_Aires",
  "America/Sao_Paulo",
  "America/Santiago",
  "America/Bogota",
  "America/Mexico_City",
  "America/Lima",
  "America/New_York",
  "Europe/Madrid",
  "UTC",
]

const DATE_FORMATS = [
  { value: "dd/MM/yyyy", example: "31/12/2026" },
  { value: "MM/dd/yyyy", example: "12/31/2026" },
  { value: "yyyy-MM-dd", example: "2026-12-31" },
]

export function ProfilePreferencesBlock() {
  const { t, changeLanguage } = useTranslation()
  const { addToast } = useToast()
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)

  const initial = user?.preferences ?? DEFAULT_PREFERENCES
  const [locale, setLocale] = useState(initial.locale)
  const [timezone, setTimezone] = useState(initial.timezone)
  const [dateFormat, setDateFormat] = useState(initial.date_format)
  const [saving, setSaving] = useState(false)

  const hasChanges = useMemo(() => {
    const current = user?.preferences ?? DEFAULT_PREFERENCES
    return locale !== current.locale || timezone !== current.timezone || dateFormat !== current.date_format
  }, [locale, timezone, dateFormat, user?.preferences])

  const save = async () => {
    setSaving(true)
    const result = await updatePreferences({ locale, timezone, date_format: dateFormat })
    setSaving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    updateUser({ preferences: result.data })
    // La UI debe reflejar el idioma recién guardado sin esperar a la próxima
    // sesión: AuthGuard sólo aplica el locale del backend en el chequeo
    // inicial (para no pisar un cambio local con un /me en vuelo), así que
    // este bloque es responsable de aplicarlo acá, igual que LanguageSwitcher.
    if (result.data && (result.data.locale === "es" || result.data.locale === "en")) {
      changeLanguage(result.data.locale)
    }
    addToast({ type: "success", title: t("profile.preferences.saved") })
  }

  return (
    <SettingsBlock
      title={t("profile.preferences.title")}
      description={t("profile.preferences.description")}
      icon={SlidersHorizontal}
      measure="prose"
      action={
        <Button size="sm" onClick={save} disabled={!hasChanges || saving}>
          {saving ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
          {saving ? t("common.saving") : t("common.save")}
        </Button>
      }
    >
      <div className="space-y-4">
        <div className="space-y-2">
          <Label>{t("profile.preferences.language")}</Label>
          <Select value={locale} onValueChange={setLocale}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="es">Español</SelectItem>
              <SelectItem value="en">English</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label>{t("profile.preferences.timezone")}</Label>
          <Select value={timezone} onValueChange={setTimezone}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TIMEZONES.map((tz) => (
                <SelectItem key={tz} value={tz}>
                  {tz}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label>{t("profile.preferences.dateFormat")}</Label>
          <Select value={dateFormat} onValueChange={setDateFormat}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {DATE_FORMATS.map((format) => (
                <SelectItem key={format.value} value={format.value}>
                  {format.value} ({format.example})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
    </SettingsBlock>
  )
}
