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

interface ChoiceFields extends BaseBlock {
  key: string;
  label: string;
  required: boolean;
  options: ChoiceOption[];
  maps_to?: MapsTo | null;
}

/** Lista desplegable u opción única: la respuesta puede cambiar el camino del formulario. */
export interface SingleChoiceBlock extends ChoiceFields {
  type: "dropdown" | "single_choice";
  /** Destino por id de opción; las opciones sin entrada siguen el `next` de la pantalla. */
  branches?: Record<string, Target>;
}

/** Opción múltiple: no admite `branches` (varias respuestas no definen un único camino). */
export interface MultipleChoiceBlock extends ChoiceFields {
  type: "multiple_choice";
}

export type ChoiceBlock = SingleChoiceBlock | MultipleChoiceBlock;

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

/**
 * Destino después de una pantalla o una respuesta:
 * - `following`: la pantalla siguiente en la lista (en la última, el final);
 * - `end`: termina el formulario (o pasa a la reserva de turno si está activa);
 * - `screen:<id>`: una pantalla posterior.
 */
export type Target = "following" | "end" | `screen:${string}`;

export interface FlowScreen {
  /** Id estable de la pantalla (`/^[A-Za-z0-9_-]{1,40}$/`, único). Se guarda en la definición. */
  id: string;
  uid?: string;
  title: string;
  footer_label: string;
  blocks: FlowBlock[];
  /** Destino después de la pantalla; por defecto `following`. */
  next?: Target;
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
