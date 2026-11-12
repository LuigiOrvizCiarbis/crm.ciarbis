import type { WhatsAppFlowCategory } from "@/data/types";

import type { AppointmentConfig, FlowDefinition } from "./types";

/** Plantillas iniciales del constructor. Sus textos son contenido del formulario, por eso van en español. */
export type FlowPreset = "blank" | "lead_capture" | "appointment";

export const FLOW_PRESETS: readonly FlowPreset[] = ["blank", "lead_capture", "appointment"];

export function isFlowPreset(value: string | null | undefined): value is FlowPreset {
  return (FLOW_PRESETS as readonly string[]).includes(value ?? "");
}

/** Valores con los que arranca la reserva de turno al activarla. */
export const DEFAULT_APPOINTMENT: AppointmentConfig = {
  date_screen_title: "Elegí el día",
  date_label: "Fecha",
  slot_screen_title: "Elegí el horario",
  slot_label: "Horario",
  confirm_label: "Reservar turno",
  reason_key: null,
};

const PRESET_NAMES: Record<FlowPreset, string> = {
  blank: "Formulario",
  lead_capture: "Captura de datos",
  appointment: "Reserva de turno",
};

const PRESET_CATEGORIES: Record<FlowPreset, WhatsAppFlowCategory[]> = {
  blank: ["OTHER"],
  lead_capture: ["LEAD_GENERATION"],
  appointment: ["APPOINTMENT_BOOKING"],
};

export function presetName(preset: FlowPreset): string {
  return PRESET_NAMES[preset];
}

export function presetCategories(preset: FlowPreset): WhatsAppFlowCategory[] {
  return [...PRESET_CATEGORIES[preset]];
}

export function presetDefinition(preset: FlowPreset): FlowDefinition {
  switch (preset) {
    case "lead_capture":
      return {
        schema: 1,
        screens: [
          {
            title: "Tus datos",
            footer_label: "Enviar",
            blocks: [
              { type: "heading", text: "Dejanos tus datos" },
              {
                type: "short_text",
                key: "nombre",
                label: "Nombre",
                input_type: "text",
                required: true,
                prefill: "contact_name",
                maps_to: "contact.name",
              },
              {
                type: "short_text",
                key: "email",
                label: "Email",
                input_type: "email",
                required: true,
                prefill: "contact_email",
                maps_to: "contact.email",
              },
              {
                type: "short_text",
                key: "telefono",
                label: "Teléfono",
                input_type: "phone",
                required: false,
                prefill: "contact_phone",
              },
              { type: "long_text", key: "consulta", label: "Consulta", required: false },
            ],
          },
        ],
        appointment: null,
      };
    case "appointment":
      return {
        schema: 1,
        screens: [
          {
            title: "Tus datos",
            footer_label: "Continuar",
            blocks: [
              { type: "heading", text: "Reservá tu turno" },
              {
                type: "short_text",
                key: "nombre",
                label: "Nombre",
                input_type: "text",
                required: true,
                prefill: "contact_name",
                maps_to: "contact.name",
              },
              {
                type: "short_text",
                key: "email",
                label: "Email",
                input_type: "email",
                required: false,
                prefill: "contact_email",
                maps_to: "contact.email",
              },
              { type: "long_text", key: "motivo", label: "Motivo", required: false },
            ],
          },
        ],
        appointment: { ...DEFAULT_APPOINTMENT, reason_key: "motivo" },
      };
    case "blank":
      return {
        schema: 1,
        screens: [
          {
            title: "Formulario",
            footer_label: "Enviar",
            blocks: [
              { type: "heading", text: "Completá tus datos" },
              {
                type: "short_text",
                key: "nombre",
                label: "Nombre",
                input_type: "text",
                required: true,
                prefill: "contact_name",
                maps_to: "contact.name",
              },
            ],
          },
        ],
        appointment: null,
      };
  }
}
