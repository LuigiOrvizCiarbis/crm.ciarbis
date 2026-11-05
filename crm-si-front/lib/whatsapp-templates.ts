import { TemplateComponent } from "@/data/types"

function findComponent(components: TemplateComponent[], type: string): TemplateComponent | undefined {
  return components.find((c) => c.type?.toUpperCase() === type)
}

export interface BodyParams {
  /**
   * Nombres de placeholder en orden de aparición. Numéricos ("1", "2") o
   * nombrados ("cliente", "comprobante") según el tipo de plantilla.
   */
  names: string[]
  /** true si la plantilla usa parámetros nombrados ({{cliente}}) */
  named: boolean
}

/**
 * Extrae los parámetros del BODY. Soporta ambos formatos de Meta:
 * - Posicionales {{1}}, {{2}}: dimensionados por el índice MÁXIMO (un {{2}}
 *   sin {{1}} requiere 2 params).
 * - Nombrados {{cliente}}: en orden de primera aparición, sin duplicados.
 */
export function extractBodyParams(components: TemplateComponent[]): BodyParams {
  const body = findComponent(components, "BODY")
  if (!body?.text) return { names: [], named: false }

  const matches = [...body.text.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g)].map((m) => m[1])
  if (matches.length === 0) return { names: [], named: false }

  const named = matches.some((name) => !/^\d+$/.test(name))
  if (named) {
    return { names: [...new Set(matches)], named: true }
  }

  const max = Math.max(...matches.map((n) => parseInt(n, 10)))
  return { names: Array.from({ length: max }, (_, i) => String(i + 1)), named: false }
}

/**
 * Preview del BODY con los valores reemplazados globalmente
 * (todas las ocurrencias de cada placeholder, no solo la primera).
 */
export function buildTemplatePreview(components: TemplateComponent[], paramValues: string[]): string {
  const body = findComponent(components, "BODY")
  if (!body?.text) return ""
  const { names } = extractBodyParams(components)
  let text = body.text
  names.forEach((name, i) => {
    text = text.replaceAll(`{{${name}}}`, paramValues[i] || `{{${name}}}`)
  })
  return text
}

/** Formato del HEADER de media, o null si no hay header o es de texto. */
export function getHeaderMediaFormat(components: TemplateComponent[]): "IMAGE" | "DOCUMENT" | "VIDEO" | null {
  const header = findComponent(components, "HEADER")
  const format = header?.format?.toUpperCase()
  if (format === "IMAGE" || format === "DOCUMENT" || format === "VIDEO") return format
  return null
}

/**
 * Plantillas que la difusión aún no puede armar: HEADER de texto con
 * placeholders, o botones con URL dinámica. (Los headers de media SÍ están
 * soportados: el diálogo pide la URL del archivo.)
 */
export function hasUnsupportedParams(components: TemplateComponent[]): boolean {
  const header = findComponent(components, "HEADER")
  if (header?.format?.toUpperCase() === "TEXT" && header.text && /\{\{[^}]+\}\}/.test(header.text)) {
    return true
  }
  if (header?.format?.toUpperCase() === "LOCATION") return true

  const buttons = findComponent(components, "BUTTONS")
  if (buttons?.buttons?.some((b) => b.url && /\{\{[^}]+\}\}/.test(b.url))) return true

  return false
}

/**
 * Origen del valor de un parámetro de plantilla. Los orígenes de contacto se
 * traducen a tokens ({{nombre}}/{{telefono}}) que el backend resuelve por
 * conversación al enviar — así una difusión sale personalizada.
 */
export type ParamSource = "contact_name" | "contact_phone" | "custom"

const PARAM_SOURCE_TOKENS: Record<Exclude<ParamSource, "custom">, string> = {
  contact_name: "{{nombre}}",
  contact_phone: "{{telefono}}",
}

/** Origen por defecto según el nombre del placeholder de la plantilla. */
export function defaultParamSource(paramName: string): ParamSource {
  if (/nombre|name|cliente|client|contacto|contact/i.test(paramName)) return "contact_name"
  if (/telefono|teléfono|phone|celular/i.test(paramName)) return "contact_phone"
  return "custom"
}

/** Valor final a enviar para un parámetro según su origen. */
export function resolveParamValue(source: ParamSource, customValue: string): string {
  return source === "custom" ? customValue : PARAM_SOURCE_TOKENS[source]
}

export interface HeaderMedia {
  /** Media id devuelto por Meta al subir el archivo (preferido) */
  id?: string
  /** URL pública del archivo (alternativa al id) */
  link?: string
  /** Nombre visible del archivo (solo documentos) */
  filename?: string
}

/**
 * Arma el array `components` del payload de envío de Meta a partir de los
 * valores cargados en el diálogo. Formato compartido entre difusión y envío
 * individual.
 */
export function buildSendComponents(
  templateComponents: TemplateComponent[],
  paramValues: string[],
  headerMedia?: HeaderMedia,
): unknown[] {
  const result: unknown[] = []

  const mediaFormat = getHeaderMediaFormat(templateComponents)
  if (mediaFormat && (headerMedia?.id || headerMedia?.link)) {
    const kind = mediaFormat.toLowerCase() as "image" | "document" | "video"
    const media: Record<string, string> = headerMedia.id
      ? { id: headerMedia.id }
      : { link: headerMedia.link as string }
    if (kind === "document" && headerMedia.filename?.trim()) {
      media.filename = headerMedia.filename.trim()
    }
    result.push({ type: "header", parameters: [{ type: kind, [kind]: media }] })
  }

  const { names, named } = extractBodyParams(templateComponents)
  if (names.length > 0) {
    result.push({
      type: "body",
      parameters: names.map((name, i) =>
        named
          ? { type: "text", parameter_name: name, text: paramValues[i] ?? "" }
          : { type: "text", text: paramValues[i] ?? "" },
      ),
    })
  }

  return result
}

// Clases literales para que Tailwind las detecte. Sin violeta: ese color es sólo de la IA.
const CATEGORY_CLASS: Record<string, string> = {
  MARKETING: "bg-primary/10 text-primary",
  UTILITY: "bg-info/10 text-info",
  AUTHENTICATION: "bg-warning/10 text-warning",
}

/** Chip de categoría de plantilla (desconocida → estilo base del Badge). */
export function templateCategoryClass(category: string | null | undefined): string {
  return (category && CATEGORY_CLASS[category]) || ""
}
