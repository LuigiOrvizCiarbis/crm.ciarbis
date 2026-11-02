import { 
  MessageCircle, 
  Instagram, 
  Facebook, 
  Linkedin, 
  Send, 
  Globe, 
  Mail,
  MessageSquareMore,
  type LucideProps,
} from "lucide-react"
import { channelIconClass } from "@/lib/utils/channelHelpers"

interface PlatformIconProps extends Omit<LucideProps, "ref"> {
  type: string
}

export function PlatformIcon({ type, className = "w-5 h-5", ...props }: PlatformIconProps) {
  const icons = {
    whatsapp: MessageCircle,
    instagram: Instagram,
    facebook: Facebook,
    linkedin: Linkedin,
    telegram: Send,
    web: Globe,
    mail: Mail,
    manual: MessageSquareMore,
  } as const

  const Icon = icons[type as keyof typeof icons] || MessageSquareMore
  
  return <Icon className={`${className} ${channelIconClass(type)}`} {...props} />
}