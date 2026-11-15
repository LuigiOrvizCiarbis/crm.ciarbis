import type { WhatsAppFlowResponseField } from "@/data/types"

import { isQuestionBlock } from "./limits"
import { isRepeaterScreen } from "./repeater"
import type { FlowDefinition } from "./types"

/** Texto inicial del cierre. Contenido para el cliente, no interfaz: va en español como los presets. */
export const DEFAULT_CLOSING_BODY = "¡Gracias! Recibimos tus respuestas."

/**
 * Respuestas que puede citar el cierre, con la definición que se
 * está editando. Espejo de FlowVersion::responseFields en el backend.
 */
export function responseFields(definition: FlowDefinition): WhatsAppFlowResponseField[] {
  const fields: WhatsAppFlowResponseField[] = []

  for (const screen of definition.screens) {
    if (isRepeaterScreen(screen)) {
      if (screen.key) fields.push({ key: screen.key, label: screen.title || screen.key })
      continue
    }
    for (const block of screen.blocks) {
      if (isQuestionBlock(block) && block.key && block.label.trim()) fields.push({ key: block.key, label: block.label })
    }
  }

  if (definition.appointment) {
    fields.push({ key: "appointment_start", label: "Turno" }, { key: "appointment_status", label: "Estado del turno" })
  }

  return fields
}
