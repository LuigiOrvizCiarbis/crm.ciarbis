import type { TemplateParameter, WhatsAppFlowResponseField, WhatsAppTemplate } from "@/data/types"
import type { ContactField } from "@/lib/api/contact-fields"
import { templateBodyParameters, templateHeaderMediaFormat } from "@/lib/whatsapp-templates"

/** De dónde pueden salir los valores: respuestas (sólo en el cierre) y datos del contacto. */
export interface ParameterContext {
  answers: WhatsAppFlowResponseField[]
  contactFields: ContactField[]
}

type Translate = (key: string, params?: Record<string, string | number>) => string

export const CONTACT_PATHS = ["contact.name", "contact.phone", "contact.email"] as const
export const ANSWER_PREFIX = "answers."
export const CUSTOM_DATA_PREFIX = "contact.custom_data."

/** Campos personalizados que se pueden poner en un texto (los archivos y grupos no). */
export function textContactFields(fields: ContactField[]): ContactField[] {
  return fields.filter((field) => field.type !== "file" && field.type !== "repeater")
}

/** Etiqueta de una respuesta; las del turno las agrega el CRM y se traducen. */
export function answerLabel(field: WhatsAppFlowResponseField, t: Translate): string {
  return field.key === "appointment_start" || field.key === "appointment_status" ? t(`flows.completion.fields.${field.key}`) : field.label
}

function defaultBodyParameter(name: string, context: ParameterContext): TemplateParameter {
  const base = { component: "body" as const, name }
  const lower = name.toLowerCase()
  if (context.answers.some((field) => field.key === name)) return { ...base, source: "field", path: `${ANSWER_PREFIX}${name}` }
  if (/nombre|name/.test(lower)) return { ...base, source: "field", path: "contact.name" }
  if (/tel|phone|celular|whatsapp/.test(lower)) return { ...base, source: "field", path: "contact.phone" }
  if (/mail|correo/.test(lower)) return { ...base, source: "field", path: "contact.email" }
  const custom = textContactFields(context.contactFields).find((field) => field.key === name)
  if (custom) return { ...base, source: "field", path: `${CUSTOM_DATA_PREFIX}${custom.key}` }
  return { ...base, source: "literal", value: "" }
}

/**
 * Parámetros que pide la plantilla, en el orden del backend: el encabezado de media primero y
 * después una variable del cuerpo por fila. Usa lo guardado y completa lo que falta.
 */
export function templateParameterRows(
  template: WhatsAppTemplate,
  stored: TemplateParameter[],
  context: ParameterContext,
): TemplateParameter[] {
  const rows: TemplateParameter[] = []
  if (templateHeaderMediaFormat(template)) {
    const header = stored.find((parameter) => parameter.component === "header")
    rows.push(header && header.source !== "field" ? { ...header, component: "header" } : { component: "header", source: "media_asset" })
  }
  return [...rows, ...bodyParameterRows(templateBodyParameters(template), stored, context)]
}

/** Una fila por variable del cuerpo: la guardada (por nombre o, sin nombre, por posición) o una sugerida. */
export function bodyParameterRows(names: string[], stored: TemplateParameter[], context: ParameterContext): TemplateParameter[] {
  const storedBody = stored.filter((parameter) => (parameter.component ?? "body") === "body")
  return names.map((name, index) => {
    const match = storedBody.find((parameter) => parameter.name === name) ?? (storedBody[index]?.name ? undefined : storedBody[index])
    return match ? { ...match, component: "body", name } : defaultBodyParameter(name, context)
  })
}

function isHttpsUrl(value: string): boolean {
  try {
    return new URL(value).protocol === "https:"
  } catch {
    return false
  }
}

export type ParameterIssue = "value" | "path" | "file" | "url"

/** Qué le falta a un parámetro para poder enviarse, o null si está completo. */
export function parameterIssue(parameter: TemplateParameter): ParameterIssue | null {
  if (parameter.component === "header") {
    if (parameter.source === "media_asset") return parameter.media_asset_id ? null : "file"
    return isHttpsUrl(parameter.value?.trim() ?? "") ? null : "url"
  }
  if (parameter.source === "literal") return parameter.value?.trim() ? null : "value"
  return parameter.path ? null : "path"
}

export function parametersComplete(parameters: TemplateParameter[]): boolean {
  return parameters.every((parameter) => parameterIssue(parameter) === null)
}

/** Deja sólo los campos de cada fuente, como los espera el backend. */
export function cleanParameters(parameters: TemplateParameter[]): TemplateParameter[] {
  return parameters.map((parameter) => {
    const base = { component: parameter.component ?? "body", ...(parameter.name ? { name: parameter.name } : {}) }
    if (parameter.source === "media_asset") return { ...base, source: "media_asset", media_asset_id: parameter.media_asset_id }
    if (parameter.source === "literal") return { ...base, source: "literal", value: parameter.value?.trim() ?? "" }
    const fallback = parameter.fallback?.trim()
    return { ...base, source: "field", path: parameter.path, ...(fallback ? { fallback } : {}) }
  })
}

export interface ParameterErrors {
  /** Errores del conjunto (cantidad de variables, plantilla). */
  general: string[]
  /** Por posición del parámetro. */
  rows: Record<number, string[]>
}

export const NO_PARAMETER_ERRORS: ParameterErrors = { general: [], rows: {} }

/** Reparte los errores 422 de `<prefix>` y `<prefix>.<n>.<campo>` entre las filas. */
export function parameterErrorsFrom(errors: Record<string, string[]>, prefix: string): ParameterErrors {
  const result: ParameterErrors = { general: [], rows: {} }
  for (const [key, messages] of Object.entries(errors)) {
    if (key === prefix) {
      result.general.push(...messages)
      continue
    }
    if (!key.startsWith(`${prefix}.`)) continue
    const index = Number(key.slice(prefix.length + 1).split(".")[0])
    if (Number.isInteger(index)) result.rows[index] = [...(result.rows[index] ?? []), ...messages]
    else result.general.push(...messages)
  }
  return result
}

/** Cómo se muestra el valor de un parámetro en la vista previa: `[Etiqueta]` o el texto fijo. */
export function parameterLabel(parameter: TemplateParameter, context: ParameterContext, t: Translate): string {
  if (parameter.source === "literal") return parameter.value?.trim() || `[${parameter.name ?? ""}]`
  const path = parameter.path ?? ""
  if (path.startsWith(ANSWER_PREFIX)) {
    const field = context.answers.find((item) => item.key === path.slice(ANSWER_PREFIX.length))
    return `[${field ? answerLabel(field, t) : path.slice(ANSWER_PREFIX.length)}]`
  }
  if (path.startsWith(CUSTOM_DATA_PREFIX)) {
    const key = path.slice(CUSTOM_DATA_PREFIX.length)
    return `[${context.contactFields.find((field) => field.key === key)?.label ?? key}]`
  }
  if ((CONTACT_PATHS as readonly string[]).includes(path)) return `[${t(`flows.journey.params.contact.${path.slice("contact.".length)}`)}]`
  return `[${parameter.name ?? path}]`
}

/** Cuerpo de la plantilla con cada variable reemplazada por su etiqueta. */
export function templateBodyPreview(
  template: WhatsAppTemplate,
  parameters: TemplateParameter[],
  context: ParameterContext,
  t: Translate,
): string {
  return bodyPreview(template.components.find((component) => component.type.toUpperCase() === "BODY")?.text ?? "", parameters, context, t)
}

/** Un texto con cada `{{variable}}` reemplazada por su etiqueta, p. ej. `[Nombre]`. */
export function bodyPreview(text: string, parameters: TemplateParameter[], context: ParameterContext, t: Translate): string {
  const byName = new Map(
    parameters.filter((parameter) => (parameter.component ?? "body") === "body").map((parameter) => [parameter.name ?? "", parameter]),
  )
  return text.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (match, name: string) => {
    const parameter = byName.get(name)
    return parameter ? parameterLabel(parameter, context, t) : `[${name}]`
  })
}
