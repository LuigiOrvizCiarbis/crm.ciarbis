/**
 * Definición del constructor visual de formularios de WhatsApp (`builder_definition`).
 * Espejo exacto del formato que valida y compila el backend (FlowDefinitionValidator /
 * FlowDefinitionCompiler): el frontend nunca arma Flow JSON.
 */

export type PrefillSource = "contact_name" | "contact_email" | "contact_phone" | `cf_${string}`;
export type MapsTo = "contact.name" | "contact.email" | `custom_data.${string}`;

export interface ChoiceOption {
  id: string;
  title: string;
}

interface BaseBlock {
  /** Id estable sólo del cliente para arrastrar y soltar; el backend lo guarda tal cual y lo ignora. */
  uid?: string;
}

export interface TextBlock extends BaseBlock {
  type: "heading" | "subheading" | "body" | "caption";
  text: string;
}

export interface ShortTextBlock extends BaseBlock {
  type: "short_text";
  key: string;
  label: string;
  input_type: "text" | "email" | "number" | "phone";
  required: boolean;
  helper_text?: string | null;
  prefill?: PrefillSource | null;
  maps_to?: MapsTo | null;
}

export interface LongTextBlock extends BaseBlock {
  type: "long_text";
  key: string;
  label: string;
  required: boolean;
  helper_text?: string | null;
  prefill?: PrefillSource | null;
  maps_to?: MapsTo | null;
}

export interface ChoiceBlock extends BaseBlock {
  type: "dropdown" | "single_choice" | "multiple_choice";
  key: string;
  label: string;
  required: boolean;
  options: ChoiceOption[];
  maps_to?: MapsTo | null;
}

export interface DateBlock extends BaseBlock {
  type: "date";
  key: string;
  label: string;
  required: boolean;
  helper_text?: string | null;
  maps_to?: MapsTo | null;
}

export interface OptInBlock extends BaseBlock {
  type: "opt_in";
  key: string;
  label: string;
  required: boolean;
  maps_to?: MapsTo | null;
}

export type QuestionBlock = ShortTextBlock | LongTextBlock | ChoiceBlock | DateBlock | OptInBlock;
export type FlowBlock = TextBlock | QuestionBlock;

export interface FlowScreen {
  uid?: string;
  title: string;
  footer_label: string;
  blocks: FlowBlock[];
}

export interface AppointmentConfig {
  date_screen_title: string;
  date_label: string;
  slot_screen_title: string;
  slot_label: string;
  confirm_label: string;
  reason_key: string | null;
}

export interface FlowDefinition {
  schema: 1;
  screens: FlowScreen[];
  appointment: AppointmentConfig | null;
}
