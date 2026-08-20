import { ChannelType } from "./enums"

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
  user: {
    id: number
    tenant_id: number
    name: string
    email: string
    role: number
    created_at: string
    updated_at: string
  }
  conversations_count?: number
  phone?: string

}

export interface Conversation {
  id: number
  channelId: number
  contact_id?: number
  contact: {id: string, name: string, phone?: string}
  last_message: string
  timestamp: string
  unread: boolean
  manual_unread?: boolean
  leadScore?: number
  stage?: "nuevo" | "calificado" | "demo" | "cierre"
  pipeline_stage_id?: number // Nuevo campo para etapas dinámicas
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

export type FilterType = 
  | "todos" 
  | "no-leidos" 
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
  buttons?: { type: string; text: string; url?: string; phone_number?: string }[]
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

export interface Message {
  id: number
  conversation_id: number
  content: string
  message_type?: "text" | "image" | "sticker" | "document" | "audio" | "video"
  media_url?: string | null
  media_full_url?: string | null
  media_mime_type?: string | null
  media_filename?: string | null
  sender_type: "user" | "contact" | "system"
  sender_id?: number
  direction: "inbound" | "outbound"
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
