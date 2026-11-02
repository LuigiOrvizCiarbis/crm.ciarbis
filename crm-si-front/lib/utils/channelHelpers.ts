import { Channel, FilterType } from "@/data/types";
import { ChannelType } from "@/data/enums";
import { parsePhoneNumberFromString } from "libphonenumber-js";

/**
 * Extrae el identificador del canal según su tipo.
 * Cada tipo de canal tiene su propio campo identificador.
 */
export function formatPhoneNumber(raw?: string | null): string | undefined {
  if (!raw) return undefined;
  const normalized = raw.trim().startsWith("+") ? raw.trim() : `+${raw.replace(/\D/g, "")}`;
  const parsed = parsePhoneNumberFromString(normalized);
  if (parsed?.isValid()) {
    return parsed.formatInternational();
  }
  return raw;
}

export function getChannelIdentifier(channel: Channel): string | undefined {
  switch (channel.type) {
    case ChannelType.WHATSAPP:
      return formatPhoneNumber(channel.whatsapp_config?.display_phone_number) || channel.whatsapp_config?.phone_number_id;

    case ChannelType.INSTAGRAM:
      return channel.instagram_config?.username
        ? `@${channel.instagram_config.username}`
        : channel.instagram_config?.ig_user_id;

    case ChannelType.FACEBOOK:
      return channel.facebook_config?.page_id;

    case ChannelType.MAIL:
      return channel.mail_config?.email_address;

    // linkedin/telegram/web: sin config en el backend todavía
    case ChannelType.LINKEDIN:
    case ChannelType.TELEGRAM:
    case ChannelType.WEB:
    case ChannelType.MANUAL:
      return undefined;

    default:
      return undefined;
  }
}

/**
 * Verifica si un canal está conectado
 */
export function isChannelConnected(channel: Channel): boolean {
  return channel.status === "active";
}

/**
 * Obtiene el número de conversaciones del canal
 */
export function getChannelConversationsCount(channel: Channel): number {
  return channel.conversations_count || 0;
}

/**
 * Helper para determinar si un canal soporta envío automático de mensajes
 */
export function canSendMessages(type: ChannelType): boolean {
  switch (type) {
    case ChannelType.WHATSAPP:
    case ChannelType.INSTAGRAM:
    case ChannelType.FACEBOOK:
    case ChannelType.TELEGRAM:
    case ChannelType.MAIL:
      return true;
    case ChannelType.LINKEDIN:
    case ChannelType.WEB:
    case ChannelType.MANUAL:
      return false;
    default:
      return false;
  }
}

/**
 * Obtiene el texto del botón de conexión según el filtro activo
 */
export function getConnectButtonLabel(filter: FilterType): string {
  const labels: Record<FilterType, string> = {
    whatsapp: "Conectar WhatsApp",
    instagram: "Conectar Instagram",
    facebook: "Conectar Facebook",
    linkedin: "Conectar LinkedIn",
    telegram: "Conectar Telegram",
    web: "Configurar chat web",
    mail: "Configurar email",
    manual: "Crear canal manual",
    todos: "Conectar canal",
    "no-leidos": "Conectar canal",
  };
  return labels[filter];
}

type ChannelKey = "whatsapp" | "instagram" | "facebook" | "linkedin" | "telegram" | "web" | "mail" | "manual";

const CHANNEL_KEY_BY_TYPE: Record<number, ChannelKey> = {
  [ChannelType.WHATSAPP]: "whatsapp",
  [ChannelType.INSTAGRAM]: "instagram",
  [ChannelType.FACEBOOK]: "facebook",
  [ChannelType.LINKEDIN]: "linkedin",
  [ChannelType.TELEGRAM]: "telegram",
  [ChannelType.WEB]: "web",
  [ChannelType.MAIL]: "mail",
  [ChannelType.MANUAL]: "manual",
};

/** Normaliza un tipo de canal numérico o string ("1", "whatsapp") a su clave en minúsculas. */
export function channelKey(type: number | string | null | undefined): string {
  if (type === null || type === undefined) return "manual";
  const asNumber = Number(type);
  if (Number.isInteger(asNumber) && CHANNEL_KEY_BY_TYPE[asNumber]) return CHANNEL_KEY_BY_TYPE[asNumber];
  return String(type).toLowerCase();
}

// Clases literales para que Tailwind las detecte.
const CHANNEL_CHIP_CLASS: Record<string, string> = {
  whatsapp: "border-green-500/30 bg-green-500/10 text-green-700 dark:bg-green-500/20 dark:text-green-400",
  instagram: "border-pink-500/30 bg-pink-500/10 text-pink-700 dark:bg-pink-500/20 dark:text-pink-400",
  facebook: "border-blue-500/30 bg-blue-500/10 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400",
  linkedin: "border-sky-500/30 bg-sky-500/10 text-sky-700 dark:bg-sky-500/20 dark:text-sky-400",
  telegram: "border-sky-500/30 bg-sky-500/10 text-sky-700 dark:bg-sky-500/20 dark:text-sky-400",
  web: "border-violet-500/30 bg-violet-500/10 text-violet-700 dark:bg-violet-500/20 dark:text-violet-400",
  mail: "border-orange-500/30 bg-orange-500/10 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400",
};

const CHANNEL_ICON_CLASS: Record<string, string> = {
  whatsapp: "text-green-600 dark:text-green-400",
  instagram: "text-pink-600 dark:text-pink-400",
  facebook: "text-blue-600 dark:text-blue-400",
  linkedin: "text-sky-600 dark:text-sky-400",
  telegram: "text-sky-600 dark:text-sky-400",
  web: "text-violet-600 dark:text-violet-400",
  mail: "text-orange-600 dark:text-orange-400",
};

const NEUTRAL_CHIP_CLASS = "border-border bg-muted text-muted-foreground";

/** Chip de categoría por canal (manual/desconocido → neutro). */
export function channelChipClass(type: number | string | null | undefined): string {
  return CHANNEL_CHIP_CLASS[channelKey(type)] ?? NEUTRAL_CHIP_CLASS;
}

/** Color de ícono por canal (manual/desconocido → neutro). */
export function channelIconClass(type: number | string | null | undefined): string {
  return CHANNEL_ICON_CLASS[channelKey(type)] ?? "text-muted-foreground";
}
