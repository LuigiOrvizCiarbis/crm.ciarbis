import { format } from "date-fns"
import { toZonedTime } from "date-fns-tz"
import { es, enUS } from "date-fns/locale"
import type { ProfilePreferences } from "@/lib/api/profile"

/**
 * Debe coincidir con User::DEFAULT_PREFERENCES en el backend
 * (app/Models/User.php) — no es la fuente de verdad, sólo el fallback local
 * mientras el perfil carga o para un usuario sin preferencias guardadas.
 */
export const DEFAULT_PREFERENCES: ProfilePreferences = {
  locale: "es",
  timezone: "America/Argentina/Buenos_Aires",
  date_format: "dd/MM/yyyy",
}

/**
 * Formatea una fecha con la timezone y el patrón de fecha del perfil.
 * `dateFormat` usa la sintaxis de date-fns (dd/MM/yyyy, MM/dd/yyyy, etc).
 */
export function formatWithPreferences(
  date: Date | string,
  preferences: ProfilePreferences | null | undefined,
  pattern?: string,
): string {
  const prefs = preferences ?? DEFAULT_PREFERENCES
  const parsed = typeof date === "string" ? new Date(date) : date
  const zoned = toZonedTime(parsed, prefs.timezone)
  const locale = prefs.locale === "en" ? enUS : es

  return format(zoned, pattern ?? prefs.date_format, { locale })
}
