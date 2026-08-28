/**
 * Formateo y parseo de campos de tipo moneda.
 *
 * El valor guardado es siempre un número plano: la divisa vive en la
 * definición del campo (options.currency). No hay conversión entre divisas —
 * un importe en USD y otro en ARS no son comparables, y el CRM no intenta
 * fingir que lo son.
 */

export const CURRENCIES = ["ARS", "USD"] as const

export type CurrencyCode = (typeof CURRENCIES)[number]

export const DEFAULT_CURRENCY: CurrencyCode = "ARS"

export function isCurrencyCode(value: unknown): value is CurrencyCode {
  return typeof value === "string" && (CURRENCIES as readonly string[]).includes(value)
}

export function resolveCurrency(currency: unknown): CurrencyCode {
  return isCurrencyCode(currency) ? currency : DEFAULT_CURRENCY
}

/**
 * Formatea para lectura: "$ 1.250.000,50".
 *
 * Se fuerza el locale es-AR para que la separación de miles no dependa del
 * navegador del usuario: dos personas del mismo tenant deben ver el mismo
 * número. Con USD se antepone el código para que no se confunda con pesos,
 * ya que ambos usan el signo "$".
 */
export function formatCurrency(value: unknown, currency: unknown): string {
  const amount = toNumber(value)
  if (amount === null) return "—"

  const code = resolveCurrency(currency)
  const formatted = new Intl.NumberFormat("es-AR", {
    style: "currency",
    currency: code,
    currencyDisplay: "symbol",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount)

  return code === "USD" && !formatted.includes("US$") ? formatted.replace("$", "US$") : formatted
}

/** Símbolo corto para prefijar el input mientras se edita. */
export function currencySymbol(currency: unknown): string {
  return resolveCurrency(currency) === "USD" ? "US$" : "$"
}

/**
 * Convierte lo tipeado a número. Acepta lo que una persona escribe de verdad
 * ("$ 1.250.000,50", "1250000.5") aplicando el mismo criterio que el
 * importador del backend: gana como decimal el separador más a la derecha, y
 * un separador que deja exactamente 3 dígitos es de miles.
 */
export function parseCurrencyInput(raw: string): number | null {
  const cleaned = raw.replace(/[^\d,.-]/g, "")
  if (cleaned === "" || cleaned === "-") return null

  const lastComma = cleaned.lastIndexOf(",")
  const lastDot = cleaned.lastIndexOf(".")
  let normalized = cleaned

  if (lastComma !== -1 && lastDot !== -1) {
    normalized =
      lastComma > lastDot
        ? cleaned.replace(/\./g, "").replace(",", ".")
        : cleaned.replace(/,/g, "")
  } else if (lastComma !== -1) {
    const decimals = cleaned.length - lastComma - 1
    normalized = decimals === 3 ? cleaned.replace(/,/g, "") : cleaned.replace(",", ".")
  } else if (lastDot !== -1) {
    const decimals = cleaned.length - lastDot - 1
    if ((cleaned.match(/\./g)?.length ?? 0) > 1 || decimals === 3) {
      normalized = cleaned.replace(/\./g, "")
    }
  }

  const parsed = Number(normalized)
  return Number.isFinite(parsed) ? parsed : null
}

function toNumber(value: unknown): number | null {
  if (typeof value === "number") return Number.isFinite(value) ? value : null
  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value)
    return Number.isFinite(parsed) ? parsed : null
  }
  return null
}
