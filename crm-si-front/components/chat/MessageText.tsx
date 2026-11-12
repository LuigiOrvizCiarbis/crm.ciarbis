"use client"

import { Fragment, type ReactNode } from "react"
import { highlightText } from "./messageThreadUtils"

/**
 * Detecta URLs (http/https/www.) y emails en texto plano. Deliberadamente no
 * matchea dominios desnudos ("empresa.com") para no generar falsos positivos
 * sobre texto común.
 */
const LINK_REGEX = /(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+|[\w.+-]+@[\w-]+\.[a-zA-Z]{2,})/gi

const TRAILING_PUNCTUATION = /[.,;:!?)\]}'"]+$/

// overflow-wrap en vez de break-all: el link baja entero de línea y sólo se
// corta si no entra en una línea completa.
const LINK_BASE_CLASS =
  "rounded-sm font-medium underline underline-offset-[3px] [overflow-wrap:anywhere] transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"

/**
 * Sobre la burbuja propia (fondo de acción) el cian sería invisible: ahí el
 * link conserva el color del texto y se distingue por peso y subrayado.
 */
function linkClass(onAccent: boolean): string {
  const tone = onAccent
    ? "text-current decoration-current/60 hover:decoration-current"
    : "text-primary decoration-primary/40 hover:decoration-primary"
  return `${LINK_BASE_CLASS} ${tone}`
}

/** Sólo estos esquemas se dejan navegar; nunca javascript:/data:. */
function isSafeHref(href: string): boolean {
  try {
    const url = new URL(href)
    return url.protocol === "http:" || url.protocol === "https:" || url.protocol === "mailto:"
  } catch {
    return false
  }
}

function buildHref(match: string): string {
  if (match.includes("@") && !match.startsWith("http") && !match.startsWith("www.")) {
    return `mailto:${match}`
  }
  return match.startsWith("www.") ? `https://${match}` : match
}

/**
 * Convierte URLs/emails de un fragmento de texto plano en <a> clickeables.
 * Recorta puntuación final que suele quedar pegada al link ("visitá x.com.")
 * sin perderla del texto visible.
 */
function autolinkSegment(text: string, keyPrefix: string, onAccent: boolean): ReactNode {
  // split con un regex de un solo grupo de captura intercala texto/match:
  // índices pares son texto plano, impares son lo que matcheó LINK_REGEX.
  const parts = text.split(LINK_REGEX)
  if (parts.length === 1) return text

  return parts.map((part, i) => {
    const isLink = i % 2 === 1
    if (!isLink) return part

    const trailingMatch = part.match(TRAILING_PUNCTUATION)
    const trailing = trailingMatch ? trailingMatch[0] : ""
    const linkText = trailing ? part.slice(0, -trailing.length) : part
    const href = buildHref(linkText)

    if (!linkText || !isSafeHref(href)) return part

    return (
      <Fragment key={`${keyPrefix}-link-${i}`}>
        <a
          href={href}
          target="_blank"
          rel="noopener noreferrer nofollow"
          className={linkClass(onAccent)}
          onClick={(e) => e.stopPropagation()}
        >
          {linkText}
        </a>
        {trailing}
      </Fragment>
    )
  })
}

/**
 * Aplica autolink como el `renderPart` de highlightText en vez de cómo un
 * segundo paso sobre su resultado: así el conteo de matchIndex que arma los
 * data-match-key de la búsqueda (y que MessageList indexa por separado sobre
 * el string plano) no se altera, y el autolink también corre dentro de un
 * <mark> cuando el texto resaltado contiene un link.
 *
 * Límite conocido: si la búsqueda activa coincide con un término que cae
 * DENTRO de una URL (ej. buscar "amazon" sobre "https://amazon.com"), el
 * split de highlightText corta la URL en dos segmentos y esa URL puntual
 * pierde el autolink mientras la búsqueda esté activa. El resaltado sigue
 * funcionando y el texto es legible; sólo ese link deja de ser clickeable
 * en ese estado transitorio.
 */
export function MessageText({
  content,
  query,
  activeMatchKey,
  matchKeyPrefix,
  onAccent = false,
}: {
  content: string
  query: string
  activeMatchKey: string | null
  matchKeyPrefix: string
  /** El texto va sobre el color de acción (burbuja propia). */
  onAccent?: boolean
}): ReactNode {
  return highlightText(content, query, activeMatchKey, matchKeyPrefix, (part, key) => (
    <Fragment key={key}>{autolinkSegment(part, key, onAccent)}</Fragment>
  ))
}
