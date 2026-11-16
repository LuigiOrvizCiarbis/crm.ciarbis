import { BUTTON_TEXT_MAX, flowButtonTarget, templateNameFrom } from "@/components/broadcasts/NewTemplateDialog"
import type { TemplateParameter, WhatsAppFlow, WhatsAppFlowSendDefaults, WhatsAppTemplate } from "@/data/types"
import { CUSTOM_DATA_PREFIX, parametersComplete } from "@/lib/template-parameters"

/** Variable válida del mensaje, como las nombradas de Meta: `{{nombre}}`. Espejo de TemplateParameterRenderer::VARIABLE. */
const VARIABLE = /\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/g

/** Variables del cuerpo, en orden y sin repetir. */
export function messageVariableNames(body: string): string[] {
  return [...new Set([...body.matchAll(VARIABLE)].map((match) => match[1]))]
}

/** Llaves que no son una variable válida (mayúsculas, espacios, acentos). */
export function invalidVariables(body: string): string[] {
  return (body.match(/\{\{.*?\}\}/g) ?? []).filter((written) => !new RegExp(`^${VARIABLE.source}$`).test(written))
}

/** Por qué el mensaje todavía no se puede pedir como plantilla. */
export type RequestProblem = "incomplete" | "variableAtEdge" | "buttonTooLong"

export function requestProblem(message: WhatsAppFlowSendDefaults): RequestProblem | null {
  const outsideBody = [message.header, message.footer, message.cta].some((text) => text?.includes("{{"))
  if (
    message.body === "" ||
    message.cta === "" ||
    outsideBody ||
    invalidVariables(message.body).length > 0 ||
    !parametersComplete(message.variables)
  ) {
    return "incomplete"
  }
  // Meta rechaza una plantilla cuyo cuerpo empieza o termina con una variable.
  if (/^\{\{|\}\}$/.test(message.body)) return "variableAtEdge"
  if (message.cta.length > BUTTON_TEXT_MAX) return "buttonTooLong"
  return null
}

const CONTACT_EXAMPLES: Record<string, string> = {
  "contact.name": "Juan",
  "contact.phone": "5491122334455",
  "contact.email": "juan@ejemplo.com",
}

/** Ejemplo que Meta pide para revisar cada variable. */
function variableExample(variable: TemplateParameter): string {
  if (variable.source === "literal") return variable.value?.trim() || variable.name || "ejemplo"
  const path = variable.path ?? ""
  return CONTACT_EXAMPLES[path] ?? (variable.fallback?.trim() || (path.startsWith(CUSTOM_DATA_PREFIX) ? path.slice(CUSTOM_DATA_PREFIX.length) : "ejemplo"))
}

/** La plantilla de apertura es el mensaje mismo, con sus variables y el botón que abre el formulario. */
export function openingTemplateComponents(flow: WhatsAppFlow, message: WhatsAppFlowSendDefaults): unknown[] {
  const examples = message.variables.map((variable) => ({ param_name: variable.name, example: variableExample(variable) }))
  return [
    ...(message.header ? [{ type: "HEADER", format: "TEXT", text: message.header }] : []),
    { type: "BODY", text: message.body, ...(examples.length > 0 ? { example: { body_text_named_params: examples } } : {}) },
    ...(message.footer ? [{ type: "FOOTER", text: message.footer }] : []),
    { type: "BUTTONS", buttons: [{ type: "FLOW", text: message.cta, ...flowButtonTarget(flow, flow.meta_flow_id) }] },
  ]
}

/** Si la plantilla dice lo mismo que el mensaje (encabezado de texto, cuerpo, pie y botón). */
export function matchesMessage(template: WhatsAppTemplate, message: WhatsAppFlowSendDefaults): boolean {
  const part = (type: string) => template.components.find((component) => component.type.toUpperCase() === type)
  const header = part("HEADER")
  if (header && header.format?.toUpperCase() !== "TEXT") return false
  const button = part("BUTTONS")?.buttons?.find((item) => item.type.toUpperCase() === "FLOW")
  return (
    (header?.text ?? null) === message.header &&
    (part("BODY")?.text ?? "") === message.body &&
    (part("FOOTER")?.text ?? null) === message.footer &&
    (button?.text ?? null) === message.cta
  )
}

/** Misma variable, salga de donde salga: compara lo que se guarda, no la forma del objeto. */
export function sameVariables(a: TemplateParameter[], b: TemplateParameter[]): boolean {
  const key = (variable: TemplateParameter) =>
    [variable.name, variable.source, variable.path ?? "", variable.value ?? "", variable.fallback ?? ""].join("\u0000")
  return a.length === b.length && a.every((variable, index) => key(variable) === key(b[index]))
}

/** `<formulario>_invitacion`, con un número si ese nombre ya existe en el idioma. */
export function suggestTemplateName(flow: WhatsAppFlow, templates: WhatsAppTemplate[] | null, language: string): string {
  const base = `${templateNameFrom(flow.name) || "formulario"}_invitacion`
  const taken = new Set((templates ?? []).filter((item) => item.language === language).map((item) => item.name))
  let name = base
  for (let suffix = 2; taken.has(name); suffix += 1) name = `${base}_${suffix}`
  return name
}
