import type { ContactField } from "@/lib/api/contact-fields";

import type { ChoiceOption, MapsTo, PrefillSource, QuestionBlock } from "./types";

/**
 * Compatibilidad entre preguntas del constructor y destinos del contacto ("Guardar en")
 * o fuentes de precarga ("Precargar con"). Es la misma tabla que valida el backend.
 */

export const CUSTOM_DATA_PREFIX = "custom_data.";
export const CUSTOM_PREFILL_PREFIX = "cf_";

const BASE_PREFILL_SOURCES: readonly PrefillSource[] = ["contact_name", "contact_email", "contact_phone"];

/** Tipos de campo cuyo valor entra en un texto de precarga (los arrays o archivos no). */
const PREFILLABLE_FIELD_TYPES: Partial<Record<ContactField["type"], true>> = {
  text: true,
  number: true,
  currency: true,
  date: true,
  boolean: true,
  select: true,
  email: true,
  url: true,
  phone: true,
};

export function customFieldKey(target: string | null | undefined): string | null {
  return target?.startsWith(CUSTOM_DATA_PREFIX) ? target.slice(CUSTOM_DATA_PREFIX.length) : null;
}

export function findField(fields: ContactField[], key: string | null): ContactField | undefined {
  return key === null ? undefined : fields.find((field) => field.key === key);
}

function fieldAccepts(field: ContactField, block: QuestionBlock): boolean {
  const shortText = block.type === "short_text" ? block : null;
  switch (field.type) {
    case "text":
      return ["short_text", "long_text", "dropdown", "single_choice"].includes(block.type);
    case "email":
      return shortText?.input_type === "email";
    case "phone":
      return shortText?.input_type === "phone";
    case "number":
      return shortText?.input_type === "number";
    case "url":
      return shortText !== null;
    case "date":
      return block.type === "date";
    case "boolean":
      return block.type === "opt_in";
    case "select":
      return block.type === "dropdown" || block.type === "single_choice";
    case "multi_select":
      return block.type === "multiple_choice";
    default:
      return false;
  }
}

/** Destinos compatibles con la pregunta, en el orden en que se ofrecen. */
export function compatibleTargets(block: QuestionBlock, fields: ContactField[]): MapsTo[] {
  const targets: MapsTo[] = [];
  if (block.type === "short_text") targets.push("contact.name");
  if (block.type === "short_text" && block.input_type === "email") targets.push("contact.email");
  for (const field of fields) {
    if (fieldAccepts(field, block)) targets.push(`${CUSTOM_DATA_PREFIX}${field.key}`);
  }
  return targets;
}

/** Opciones fijas de una pregunta guardada en un campo de selección: las `choices` del campo. */
export function lockedOptions(block: QuestionBlock, fields: ContactField[]): ChoiceOption[] | null {
  const field = findField(fields, customFieldKey(block.maps_to));
  if (!field || (field.type !== "select" && field.type !== "multi_select")) return null;
  return (field.options?.choices ?? []).map((choice) => ({ id: choice, title: choice }));
}

export function supportsPrefill(block: QuestionBlock): boolean {
  return block.type === "short_text" || block.type === "long_text";
}

/** Fuentes de precarga que se ofrecen en "Precargar con". */
export function prefillSources(fields: ContactField[]): PrefillSource[] {
  return [
    ...BASE_PREFILL_SOURCES,
    ...fields
      .filter((field) => PREFILLABLE_FIELD_TYPES[field.type])
      .map((field): PrefillSource => `${CUSTOM_PREFILL_PREFIX}${field.key}`),
  ];
}

/** Una precarga es válida si es una de las base o un campo personalizado que existe. */
export function isPrefillKnown(source: string, fields: ContactField[]): boolean {
  if ((BASE_PREFILL_SOURCES as readonly string[]).includes(source)) return true;
  if (!source.startsWith(CUSTOM_PREFILL_PREFIX)) return false;
  return findField(fields, source.slice(CUSTOM_PREFILL_PREFIX.length)) !== undefined;
}
