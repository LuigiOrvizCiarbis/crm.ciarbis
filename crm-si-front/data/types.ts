import { ChannelType } from "./enums"
import type { FlowDefinition } from "@/lib/flow-builder/types"

export type TranslationLanguage = "es" | "en" | "pt" | "fr" | "it" | "de" | "zh"


export interface Channel {
  id:  number
  tenant_id: number
  user_id: number
  type: ChannelType  // Usa el enum sincronizado
  name: string
  status: "active" | "disconnected"
  created_at: string
  updated_at: string
  whatsapp_config?: {
    id: number
    channel_id: number
    phone_number_id: string
    display_phone_number?: string
    waba_id: string
    verify_token: string | null
    /** El número está en la app WhatsApp Business (coexistence). null = sin chequear. */
    is_on_biz_app?: boolean | null
    created_at: string
    updated_at: string
  }
  instagram_config?: {
    id: number
    tenant_id: number
    ig_user_id: string
    page_id: string
    username: string | null
    created_at: string
    updated_at: string
  }
  /**
   * Canal Messenger. La relación en Laravel se llama facebookConfig() y apunta
   * al modelo MessengerConfig (channels.messenger_config_id), así que la clave
   * del JSON es facebook_config.
   *
   * No incluye el page_access_token: está en $hidden del modelo.
   */
  facebook_config?: {
    id: number
    tenant_id: number
    page_id: string
    page_name: string | null
    created_at: string
    updated_at: string
  }
  mail_config?: {
    id: number
    email_address: string
    from_name: string | null
    imap_host: string
    imap_port: number
    smtp_host: string
    smtp_port: number
    last_synced_at: string | null
    last_error: string | null
  }
  /** Puede venir null: el backend no siempre resuelve el usuario que conectó el canal. */
  user: {
    id: number
    tenant_id: number
    name: string
    email: string
    role: number
    created_at: string
    updated_at: string
  } | null
  conversations_count?: number
  phone?: string
  handoff_responsible_user_id?: number | null
  handoff_whatsapp_alert_enabled?: boolean
  users?: Array<{ id: number; name: string; email: string }>
}

export interface WhatsAppGroupSummary {
  id: number
  subject: string
  status: "pending" | "active" | "suspended" | "deleted" | "failed"
  group_id: string | null
  total_participant_count: number
  invite_link: string | null
}

/** Entrada de un contacto desde un anuncio de Meta (CTWA / Messenger / Instagram). */
export interface AdReferral {
  id: number
  platform: "whatsapp" | "facebook" | "instagram"
  source_type: "ad" | "post"
  ad_id: string | null
  ref: string | null
  headline: string | null
  body: string | null
  source_url: string | null
  media_type: string | null
  media_url: string | null
  conversation_id: number | null
  channel_id: number
  referred_at: string
}

/** Anuncio por el que entraron chats visibles para el usuario: opción del filtro por anuncio. */
export interface ConversationAd {
  ad_id: string
  headline: string | null
  source_type: AdReferral["source_type"]
}

export interface Conversation {
  id: number
  channelId: number
  contact_id?: number
  // null en conversaciones de grupo (kind === "group"): un grupo no tiene un
  // contacto único, ver whatsapp-group-panel / conversation-header.
  contact: {id: string, name: string, phone?: string, avatar_url?: string | null} | null
  kind?: "direct" | "group"
  group?: WhatsAppGroupSummary
  last_message: string
  timestamp: string
  unread: boolean
  manual_unread?: boolean
  leadScore?: number
  stage?: "nuevo" | "calificado" | "demo" | "cierre"
  pipeline_stage_id?: number // Nuevo campo para etapas dinámicas
  /** Etapas de las oportunidades vinculadas al chat, de cualquier pipeline. */
  opportunity_stage_ids?: number[]
  priority?: "baja" | "media" | "alta" | "hot"
  assigneeId?: number | string
  archived?: boolean,
  aiAutoreplyEnabled?: boolean,
  contactLanguage?: TranslationLanguage,
  channel?: Channel,
  last_message_at?: string,
  created_at?: string,
  unread_count?: number,
  messages?: Message[]
  tags?: Tag[]
  matchedMessageSnippet?: string
  humanHandoff?: {
    id: number
    status: "pending" | "acknowledged"
    reason?: string | null
    summary?: string | null
    assigned_to?: number | null
    notifications?: Array<{ destination_type: string; status: string; error?: string | null }>
  } | null
  /** Último anuncio de Meta por el que entró la conversación (bandeja y detalle). */
  adReferral?: AdReferral | null
  /** Ids de todos los anuncios por los que entró, no sólo el último: los usa el filtro por anuncio. */
  adIds?: string[]
}

export interface Tag {
  id: number
  name: string
  slug: string
  color: string
  type: string | null
  description: string | null
  is_system: boolean
}

/** Vistas de la bandeja de Chats. "review" es la bandeja de mails por revisar. */
export type ConversationView = "inbox" | "unread" | "waiting" | "archived" | "review"

export type FilterType = 
  | "todos" 
  | "whatsapp" 
  | "instagram" 
  | "facebook" 
  | "linkedin"    // ✅ Ahora soportado en backend
  | "telegram"    // ✅ Ahora soportado en backend
  | "web"         // ✅ Ahora soportado en backend
  | "mail"        // ✅ Ahora soportado en backend
  | "manual"
export interface TeamMember {
  id: string
  name: string
  role: string
}

export interface FilterButton {
  key: string
  label: string
  icon: React.ReactNode
}

export interface TemplateComponent {
  type: string
  text?: string
  format?: string
  parameters?: { type: string; text?: string }[]
  /** Ejemplos enviados a revisión; en encabezados con media, `header_handle` trae la URL del ejemplo. */
  example?: { header_handle?: string[]; body_text_named_params?: { param_name: string; example: string }[] }
  buttons?: {
    /** QUICK_REPLY | URL | PHONE_NUMBER | COPY_CODE | FLOW */
    type: string
    text: string
    url?: string
    phone_number?: string
    flow_id?: string
    /** Alternativa a flow_id: el backend (WhatsAppTemplate::flowButton) también resuelve el formulario por nombre. */
    flow_name?: string
    flow_action?: "navigate" | "data_exchange"
    navigate_screen?: string
  }[]
}

export type WhatsAppFlowStatus = "DRAFT" | "PUBLISHED" | "DEPRECATED" | "BLOCKED" | "THROTTLED"

export type WhatsAppFlowCategory =
  | "SIGN_UP"
  | "SIGN_IN"
  | "APPOINTMENT_BOOKING"
  | "LEAD_GENERATION"
  | "CONTACT_US"
  | "CUSTOMER_SUPPORT"
  | "SURVEY"
  | "OTHER"

export interface WhatsAppFlowFieldMapping {
  response_key: string
  target: string
}

export interface WhatsAppFlowValidationError {
  error?: string
  error_type?: string
  message: string
  line_start?: number
  line_end?: number
  column_start?: number
  column_end?: number
  pointers?: unknown[]
}

export interface WhatsAppFlow {
  id: number
  whatsapp_config_id: number
  meta_flow_id: string
  name: string
  status: WhatsAppFlowStatus
  categories: WhatsAppFlowCategory[]
  json_version: string | null
  data_api_version: string | null
  uses_endpoint: boolean
  endpoint_uri: string | null
  validation_errors: WhatsAppFlowValidationError[]
  field_mappings: WhatsAppFlowFieldMapping[]
  screens: string[]
  has_published_version: boolean
  has_builder_definition: boolean
  /** Textos con los que se envía; el backend completa los que falten. */
  send_defaults: WhatsAppFlowSendDefaults
  /** false: `send_defaults` son los sugeridos (primer título del constructor y «Completar»), sin guardar. */
  send_defaults_saved: boolean
  /** Plantilla predeterminada y variables de cada plantilla que abre el formulario. */
  opening: WhatsAppFlowOpening
  /** Plantillas de la misma cuenta cuyo botón FLOW abre este formulario. */
  send_templates: WhatsAppFlowSendTemplate[]
  /** Plantilla que se usa fuera de la ventana de 24 h: la elegida si está lista; si no, la primera lista. */
  effective_template_id: number | null
  /** Lo que el CRM manda en el chat al recibir la respuesta; null si no manda nada. */
  completion_message: WhatsAppFlowCompletionMessage | null
  /** Respuestas de la versión publicada que el cierre puede citar (vacío en formularios JSON o sin publicar). */
  response_fields: WhatsAppFlowResponseField[]
  synced_at: string | null
  published_at: string | null
  updated_at: string | null
  /** Sólo en el detalle (`getFlow`). */
  flow_json?: string | null
  builder_definition?: FlowDefinition | null
}

export interface WhatsAppFlowSendDefaults {
  /** Sin imagen. Meta admite un solo encabezado: con imagen, este queda en null. */
  header: string | null
  /** Imagen de encabezado (reemplaza al de texto). */
  image: WhatsAppFlowImage | null
  /** Puede llevar `{{variables}}`; `variables` dice de dónde sale cada una. */
  body: string
  footer: string | null
  cta: string
  /** Variables del cuerpo, con la forma de los parámetros de plantilla (dato del contacto o texto fijo). */
  variables: TemplateParameter[]
}

/** Parámetro de una plantilla: misma forma que en la acción de automatización `whatsapp_template`. */
export interface TemplateParameter {
  /** Por defecto `body`. */
  component?: "body" | "header"
  /** Variable del cuerpo ({{name}}), o el índice "1" en plantillas posicionales. */
  name?: string
  source: "literal" | "field" | "media_asset"
  /** literal: texto fijo; en un encabezado de media, la URL https del archivo. */
  value?: string
  /** field: contact.name | contact.phone | contact.email | contact.custom_data.<key> | answers.<key> (sólo en el cierre). */
  path?: string
  /** Si el valor queda vacío. */
  fallback?: string
  /** media_asset: archivo subido con uploadMediaAsset. */
  media_asset_id?: number
}

export interface WhatsAppFlowOpening {
  /** Elegida a mano; null = automática. Puede apuntar a una que ya no está lista. */
  default_template_id: number | null
  /** Variables configuradas por id de plantilla. */
  templates: Record<string, { parameters: TemplateParameter[] }>
}

export interface WhatsAppFlowSendTemplate {
  id: number
  name: string
  language: string
  status: WhatsAppTemplate["status"]
  /** Aprobada y con las variables completas. */
  ready: boolean
  issue: null | "not_approved" | "parameters"
}

/** Imagen de encabezado de la apertura o del cierre de texto: archivo del espacio o URL https. */
export type WhatsAppFlowImage =
  | { source: "media_asset"; media_asset_id: number }
  | { source: "literal"; value: string }

export type WhatsAppFlowCompletionMessage =
  | {
      type: "text"
      body: string
      /** Encabezado de texto y pie: sólo con botón (mensaje interactivo); con imagen no hay encabezado de texto. */
      header: string | null
      footer: string | null
      /** El botón abre `url`; los dos van juntos o ninguno. */
      button_text: string | null
      url: string | null
      image: WhatsAppFlowImage | null
      /** De dónde sale cada `{{clave}}`: respuesta, dato del contacto o texto fijo. Sin configurar, es la respuesta con esa clave. */
      variables: TemplateParameter[]
    }
  | { type: "template"; template_id: number; parameters: TemplateParameter[] }

export type WhatsAppFlowTextCompletion = Extract<WhatsAppFlowCompletionMessage, { type: "text" }>

export interface WhatsAppFlowResponseField {
  key: string
  label: string
}

export interface WhatsAppFlowEncryptionStatus {
  configured: boolean
  uploaded_at: string | null
  endpoint_url: string | null
  signature_status: string | null
}

export interface MessageFlowProgress {
  screens_done: number
  last_screen_title: string | null
  saved_fields: string[]
  updated_at: string
}

export type MessageInteractive =
  | {
      kind: "flow"
      whatsapp_flow_id: number
      flow_name: string
      session_id: number
      body: string
      cta: string
      header?: string | null
      /** URL de la imagen de encabezado de la apertura, si la llevó. */
      image?: string | null
      footer?: string | null
      /** Avance del cliente en el formulario; llega por `message.edited`. */
      progress?: MessageFlowProgress | null
      session_status?: "pending" | "completed" | "superseded" | null
    }
  | {
      kind: "flow_response"
      whatsapp_flow_id: number | null
      flow_name: string | null
      session_id: number | null
      response: Record<string, unknown>
      /** Etiqueta de cada pregunta del constructor (clave → etiqueta); falta en mensajes viejos o en formularios JSON. */
      labels?: Record<string, string>
      /** Respuestas que no se pudieron guardar en el contacto (clave → motivo, que ya incluye la etiqueta). */
      mapping_errors?: Record<string, string>
      /** Grupos repetibles respondidos: sus filas también están en `response[key]`. */
      groups?: FlowResponseGroup[]
    }
  | {
      /** Mensaje al terminar: un texto normal; el botón de link también va en `content`. */
      kind: "flow_completion"
      whatsapp_flow_id: number
      button: { text: string; url: string } | null
    }

export interface FlowResponseGroup {
  key: string
  label: string
  columns: Array<{ key: string; label: string }>
  rows: Array<Record<string, unknown>>
}

export interface WhatsAppTemplate {
  id: number
  tenant_id: number
  whatsapp_config_id: number
  external_id: string
  name: string
  language: string
  category: "MARKETING" | "UTILITY" | "AUTHENTICATION"
  status: "APPROVED" | "PENDING" | "REJECTED" | "DISABLED"
    | "IN_APPEAL" | "PENDING_DELETION" | "DELETED" | "PAUSED" | "LIMIT_EXCEEDED" | "UNKNOWN"
  rejected_reason?: string | null
  components: TemplateComponent[]
  synced_at: string
  created_at: string
  updated_at: string
}

export interface MessageReaction {
  emoji: string
  count: number
  /** Sólo reacciones de usuarios del CRM; permite derivar "reaccioné yo" sin más queries. */
  reactor_user_ids?: number[]
  /** Sólo lo manda el back en la respuesta HTTP; en realtime se deriva de reactor_user_ids. */
  reacted_by_me?: boolean
}

export interface LinkPreview {
  id: number
  url: string
  title?: string | null
  description?: string | null
  site_name?: string | null
  image_url?: string | null
  status: "pending" | "ok" | "failed"
  fetched_at?: string | null
  failure_reason?: string | null
}

export type WhatsAppCheckoutStatus = "cart" | "placing" | "awaiting_payment" | "paid" | "cancelled" | "expired"

/** `GET /api/conversations/{id}/whatsapp-checkout` (WhatsAppCheckoutResource). */
export interface WhatsAppCheckoutSummary {
  id: number
  status: WhatsAppCheckoutStatus
  order_number: string | null
  total: number | null
  currency: string | null
  items: { name: string; variant_label: string | null; quantity: number }[]
  payment_url: string | null
  payment_expires_at: string | null
  paid_at: string | null
  shipped_notified_at: string | null
  tracking_number: string | null
  order_admin_url: string | null
}

export interface Message {
  id: number
  conversation_id: number
  content: string
  message_type?: "text" | "image" | "sticker" | "document" | "audio" | "video" | "contacts" | "flow" | "flow_response" | "order"
  contacts?: SharedContact[] | null
  interactive?: MessageInteractive | null
  media_url?: string | null
  media_full_url?: string | null
  media_mime_type?: string | null
  media_filename?: string | null
  link_preview?: LinkPreview | null
  sender_type: "user" | "contact" | "system"
  sender_id?: number
  // Autoría dentro de un grupo: quién de los participantes escribió. No
  // viene de un morphTo (sin morph map registrado en el back), se carga en
  // batch y se adjunta al serializar.
  sender?: { id: number; name: string } | null
  direction: "inbound" | "outbound"
  // wamid de WhatsApp. Lo necesita canReact: sin esto, Meta no sabe a qué
  // mensaje reaccionar.
  external_id?: string | null
  delivered_at?: string | null
  read_at?: string | null
  played_at?: string | null
  failed_at?: string | null
  error_message?: string | null
  edited_at?: string | null
  original_content?: string | null
  deleted_at?: string | null
  mail_details?: MailMessageDetails | null
  mail_attachments?: Message[]
  mail_parent_message_id?: number | null
  created_at: string
  updated_at?: string
  interactions?: Array<{ type: string; value?: string | null; content?: string | null; occurred_at: string }>
  reaction_summary?: MessageReaction[] | null
  reply_to_message_id?: number | null
  reply_to?: MessageReplyPreview | null
}

export interface MessageReplyPreview {
  id: number
  conversation_id: number
  content: string | null
  message_type?: Message["message_type"]
  sender_type: Message["sender_type"]
  sender_id?: number | null
  sender?: { id: number; name: string } | null
  direction: Message["direction"]
  media_url?: string | null
  media_full_url?: string | null
  media_mime_type?: string | null
  media_filename?: string | null
  deleted_at?: string | null
}

export interface SharedContact {
  name: { formatted_name: string; first_name?: string; last_name?: string; middle_name?: string; suffix?: string; prefix?: string }
  phones?: Array<{ phone?: string; type?: string; wa_id?: string }>
  emails?: Array<{ email?: string; type?: string }>
  org?: { company?: string; department?: string; title?: string }
  urls?: Array<{ url?: string; type?: string }>
  addresses?: Array<{ street?: string; city?: string; state?: string; zip?: string; country?: string; country_code?: string; type?: string }>
  birthday?: string
}

export interface MailAddress {
  email: string
  name?: string | null
}

export interface MailMessageDetails {
  id: number
  message_id: number
  subject?: string | null
  body_text?: string | null
  body_html?: string | null
  from?: MailAddress | null
  to?: MailAddress[] | null
  cc?: MailAddress[] | null
  bcc?: MailAddress[] | null
  reply_to?: MailAddress | null
  in_reply_to?: string[] | null
  references?: string[] | null
  has_remote_images?: boolean
}

export type ScheduledMessageStatus = "scheduled" | "queued" | "sending" | "sent" | "failed" | "cancelled"

export interface ScheduledMessage {
  id: number
  conversation_id: number
  user_id: number
  user?: { id: number; name: string }
  message_type: "text" | "image" | "audio"
  content: string | null
  media_url: string | null
  media_mime: string | null
  voice: boolean
  status: ScheduledMessageStatus
  scheduled_for: string
  sent_at: string | null
  message_id: number | null
  error_reason: string | null
  error_message: string | null
  created_at: string
}
