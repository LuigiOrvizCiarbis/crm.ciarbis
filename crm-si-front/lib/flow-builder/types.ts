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
  /** Base64 de un JPEG o PNG (sin prefijo `data:`), hasta 100 KB. */
  image?: string | null;
  alt_text?: string | null;
}

interface BaseBlock {
  /** Id estable sólo del cliente para arrastrar y soltar; el backend lo guarda tal cual y lo ignora. */
  uid?: string;
}

export interface TextBlock extends BaseBlock {
  type: "heading" | "subheading" | "body" | "caption";
  text: string;
}

export type ImageSize = "small" | "medium" | "large";
export type ScaleType = "contain" | "cover";

/** Imagen a todo el ancho. `src` es base64 de un JPEG o PNG (sin prefijo `data:`); vacío hasta que se sube. */
export interface ImageBlock extends BaseBlock {
  type: "image";
  src: string;
  alt_text?: string | null;
  size: ImageSize;
  scale_type: ScaleType;
}

export interface CarouselImage {
  src: string;
  alt_text: string;
}

export interface ImageCarouselBlock extends BaseBlock {
  type: "image_carousel";
  images: CarouselImage[];
  aspect_ratio: "4:3" | "16:9";
  scale_type: ScaleType;
}

export type MediaBlock = ImageBlock | ImageCarouselBlock;

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
  /** Tamaño de las imágenes de las opciones; Meta lo admite en opción única y múltiple, no en la lista. */
  media_size?: "regular" | "large" | null;
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
export type FlowBlock = TextBlock | MediaBlock | QuestionBlock;

/**
 * Destino después de una pantalla o una respuesta:
 * - `following`: la pantalla siguiente en la lista (en la última, el final);
 * - `end`: termina el formulario (o pasa a la reserva de turno si está activa);
 * - `screen:<id>`: una pantalla posterior.
 */
export type Target = "following" | "end" | `screen:${string}`;

interface ScreenBase {
  /** Id estable de la pantalla (`/^[A-Za-z0-9_-]{1,40}$/`, único). Se guarda en la definición. */
  id: string;
  uid?: string;
  title: string;
  footer_label: string;
  /** Destino después de la pantalla; por defecto `following`. */
  next?: Target;
  /** Posición en el diagrama del constructor; el backend la guarda y la ignora. */
  position?: CanvasPosition;
}

export interface ContentScreen extends ScreenBase {
  type?: "screen";
  blocks: FlowBlock[];
}

/** Tipos de subcampo de un repeater del CRM (todos entran en un formulario). */
export type RepeaterFieldType = "text" | "number" | "currency" | "date" | "boolean" | "select" | "email" | "url" | "phone";

/** Copia de un subcampo activo del repeater; `label` es la etiqueta corta del formulario. */
export interface RepeaterField {
  key: string;
  label: string;
  type: RepeaterFieldType;
  required: boolean;
  choices?: string[];
  currency?: string;
}

/**
 * Grupo repetible: una pantalla por fila (hasta `max_rows`) con "¿Agregar otro?", ligado a un
 * campo repeater del contacto. Lo expande el backend (FlowDefinitionExpander) y `expand.ts`.
 */
export interface RepeaterScreen extends ScreenBase {
  type: "repeater";
  /** Prefijo de las respuestas (`<key>_<fila>_<n>`); mismas reglas que la clave de una pregunta. */
  key: string;
  /** Clave del campo repeater del contacto; vacío hasta que se elige. */
  field_key: string;
  /** Título de cada fila; `{n}` es el número de fila. */
  row_title: string;
  more_label: string;
  max_rows: number;
  /** Mínimo de filas del campo en el CRM, copiado al elegirlo. */
  min_rows: number;
  /** Con mínimo 0: pregunta antes si hay algo para cargar. */
  ask_first: boolean;
  ask_first_label: string;
  fields: RepeaterField[];
}

export type FlowScreen = ContentScreen | RepeaterScreen;

export interface CanvasPosition {
  x: number;
  y: number;
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
