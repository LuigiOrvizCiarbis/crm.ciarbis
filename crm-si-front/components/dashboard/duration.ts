import type { useTranslation } from "@/hooks/useTranslation"

type Translate = ReturnType<typeof useTranslation>["t"]

const decimal = new Intl.NumberFormat("es-AR", { maximumFractionDigits: 1 })

/** "8 s", "18 min", "2 h 5 min", "1,5 días": la unidad cambia con la magnitud para que el número se lea. */
export function formatSeconds(t: Translate, seconds: number): string {
  if (seconds < 60) return t("dashboard.efficiency.seconds", { value: Math.max(1, Math.round(seconds)) })
  return formatMinutes(t, seconds / 60)
}

export function formatMinutes(t: Translate, minutes: number): string {
  if (minutes < 60) return t("dashboard.efficiency.minutes", { value: Math.max(1, Math.round(minutes)) })
  if (minutes < 60 * 24) {
    const hours = Math.floor(minutes / 60)
    const rest = Math.round(minutes - hours * 60)
    return rest > 0 ? t("dashboard.efficiency.hoursMinutes", { hours, minutes: rest }) : t("dashboard.efficiency.hours", { value: hours })
  }
  return formatDays(t, minutes / (60 * 24))
}

export function formatDays(t: Translate, days: number): string {
  const rounded = Math.round(days * 10) / 10
  return t(`dashboard.efficiency.days_${rounded === 1 ? "one" : "other"}`, { value: decimal.format(rounded) })
}
