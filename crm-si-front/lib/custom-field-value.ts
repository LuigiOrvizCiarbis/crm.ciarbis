import { format } from "date-fns"
import { enUS, es } from "date-fns/locale"
import type { ContactFieldType } from "@/lib/api/contact-fields"
import { formatCurrency } from "@/lib/currency"
import type { Language } from "@/lib/i18n"

type Translate = (key: string, params?: Record<string, string | number>) => string

interface FormatOptions {
  t: Translate
  language: Language
  /** Divisa del campo (`options.currency`) para los de tipo moneda. */
  currency?: unknown
}

/** Tipos que se leen como cantidad: en una tabla van alineados a la derecha y con tabular-nums. */
export function isNumericFieldType(type: ContactFieldType | undefined): boolean {
  return type === "number" || type === "currency"
}

/**
 * Valor de un campo custom (contacto o producto) listo para leer en una celda o una ficha.
 * Vacío es "—" para que la columna no quede con huecos que parezcan un error de carga.
 */
export function formatCustomFieldValue(
  value: unknown,
  type: ContactFieldType | undefined,
  { t, language, currency }: FormatOptions,
): string {
  if (value === null || value === undefined || value === "") return "—"
  if (type === "currency") return formatCurrency(value, currency)
  if (type === "number" && Number.isFinite(Number(value))) {
    return new Intl.NumberFormat(language, { useGrouping: "always", maximumFractionDigits: 20 }).format(Number(value))
  }
  if (type === "repeater" && Array.isArray(value)) {
    return t(`customFieldValue.elements_${value.length === 1 ? "one" : "other"}`, { count: value.length })
  }
  if (Array.isArray(value)) return value.length > 0 ? value.join(", ") : "—"
  if (type === "boolean" || typeof value === "boolean") return value ? t("common.yes") : t("common.no")
  if (type === "date" && typeof value === "string") return formatDateValue(value, language)
  // Un campo tipo archivo guarda un media_asset_id; el número crudo no aporta.
  if (type === "file") return t("customFieldValue.file")
  return String(value)
}

/**
 * Un campo `date` guarda una fecha pelada (`2026-09-14`), sin hora ni zona. `new Date()` la
 * interpreta como medianoche UTC y, en hora local, un tenant al oeste de Greenwich veía el día
 * anterior; por eso la fecha se arma en local.
 */
function formatDateValue(value: string, language: Language): string {
  const parts = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  const parsed = parts ? new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])) : new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return format(parsed, "d MMM yyyy", { locale: language === "en" ? enUS : es })
}
