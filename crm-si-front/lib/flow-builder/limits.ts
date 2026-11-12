import type { ChoiceBlock, FlowBlock, QuestionBlock, TextBlock } from "./types";

/**
 * Límites del constructor visual. Son los mismos que aplica el backend
 * (FlowDefinitionValidator), tomados de la referencia "Components" de Meta.
 */

/** El `routing_model` de Meta admite como máximo 10 ramas. */
export const MAX_SCREENS = 10;
export const MAX_OPT_IN_PER_SCREEN = 5;
export const FOOTER_LABEL_MAX = 35;
export const HELPER_TEXT_MAX = 80;
export const OPTION_TITLE_MAX = 30;
export const NAME_MAX = 200;

export const KEY_MAX = 40;
export const KEY_PATTERN = /^[a-z][a-z0-9_]{0,39}$/;
/** Claves que usa el endpoint de datos y se mezclan con las respuestas en la sesión. */
export const RESERVED_KEYS: readonly string[] = ["crm_action", "next_screen", "flow_token", "date", "slot", "error_message"];
export const RESERVED_KEY_PREFIX = "appointment_";
/** Meta rechaza claves de datos de pantalla que empiezan con "required". */
export const FORBIDDEN_KEY_PREFIX = "required";

export const TEXT_MAX: Record<TextBlock["type"], number> = {
  heading: 80,
  subheading: 80,
  body: 4096,
  caption: 409,
};

export const LABEL_MAX: Record<QuestionBlock["type"], number> = {
  short_text: 20,
  long_text: 20,
  dropdown: 20,
  single_choice: 30,
  multiple_choice: 30,
  date: 40,
  opt_in: 120,
};

export const OPTION_COUNT_MAX: Record<ChoiceBlock["type"], number> = {
  dropdown: 200,
  single_choice: 20,
  multiple_choice: 20,
};

export const APPOINTMENT_LABEL_MAX = {
  date_label: 20,
  slot_label: 20,
  confirm_label: 35,
} as const;

export const TEXT_BLOCK_TYPES: readonly TextBlock["type"][] = ["heading", "subheading", "body", "caption"];
export const QUESTION_BLOCK_TYPES: readonly QuestionBlock["type"][] = [
  "short_text",
  "long_text",
  "dropdown",
  "single_choice",
  "multiple_choice",
  "date",
  "opt_in",
];

export function isTextBlock(block: FlowBlock): block is TextBlock {
  return (TEXT_BLOCK_TYPES as readonly string[]).includes(block.type);
}

export function isQuestionBlock(block: FlowBlock): block is QuestionBlock {
  return !isTextBlock(block);
}

export function isChoiceBlock(block: FlowBlock): block is ChoiceBlock {
  return block.type === "dropdown" || block.type === "single_choice" || block.type === "multiple_choice";
}

export function isReservedKey(key: string): boolean {
  return RESERVED_KEYS.includes(key) || key.startsWith(RESERVED_KEY_PREFIX);
}

export function hasForbiddenKeyPrefix(key: string): boolean {
  return key.startsWith(FORBIDDEN_KEY_PREFIX);
}
