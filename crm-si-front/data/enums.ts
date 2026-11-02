import { FilterType } from './types'

/**
 * Channel Type enum - sincronizado EXACTAMENTE con App\Enums\ChannelType del backend.
 * CRÍTICO: Los valores deben coincidir para evitar errores de mapeo.
 */
export enum ChannelType {
  WHATSAPP = 1,
  INSTAGRAM = 2,
  FACEBOOK = 3,
  LINKEDIN = 4,
  TELEGRAM = 5,
  WEB = 6,
  MAIL = 7,
  MANUAL = 8,
}

/**
 * Helper para mapear ChannelType a FilterType para filtros UI
 */
export function channelTypeToFilterType(type: ChannelType | undefined): FilterType {
  switch (type) {
    case ChannelType.WHATSAPP:
      return "whatsapp"
    case ChannelType.INSTAGRAM:
      return "instagram"
    case ChannelType.FACEBOOK:
      return "facebook"
    case ChannelType.LINKEDIN:
      return "linkedin"
    case ChannelType.TELEGRAM:
      return "telegram"
    case ChannelType.WEB:
      return "web"
    case ChannelType.MAIL:
      return "mail"
    case ChannelType.MANUAL:
      return "manual"
    default:
      console.warn(`[channelTypeToFilterType] Unknown ChannelType: ${type}`)
      return "manual"
  }
}

/**
 * Helper inverso para obtener ChannelType desde FilterType
 */
export function filterTypeToChannelType(filter: FilterType): ChannelType | null {
  switch (filter) {
    case "whatsapp":
      return ChannelType.WHATSAPP
    case "instagram":
      return ChannelType.INSTAGRAM
    case "facebook":
      return ChannelType.FACEBOOK
    case "linkedin":
      return ChannelType.LINKEDIN
    case "telegram":
      return ChannelType.TELEGRAM
    case "web":
      return ChannelType.WEB
    case "mail":
      return ChannelType.MAIL
    case "manual":
      return ChannelType.MANUAL
    case "todos":
    case "no-leidos":
      return null
    default:
      return null
  }
}

/**
 * Helper para obtener nombre display amigable
 */
export function getChannelDisplayName(type: ChannelType): string {
  switch (type) {
    case ChannelType.WHATSAPP:
      return 'WhatsApp'
    case ChannelType.INSTAGRAM:
      return 'Instagram'
    case ChannelType.FACEBOOK:
      return 'Facebook'
    case ChannelType.LINKEDIN:
      return 'LinkedIn'
    case ChannelType.TELEGRAM:
      return 'Telegram'
    case ChannelType.WEB:
      return 'Chat web'
    case ChannelType.MAIL:
      return 'Email'
    case ChannelType.MANUAL:
      return 'Manual'
    default:
      return 'Desconocido'
  }
}