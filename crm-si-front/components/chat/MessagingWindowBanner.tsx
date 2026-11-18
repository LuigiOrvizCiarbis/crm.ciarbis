"use client"

import { format } from "date-fns"
import { enUS, es } from "date-fns/locale"
import { AlertTriangle, Clock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { ChannelType } from "@/data/enums"
import { useTranslation } from "@/hooks/useTranslation"

interface MessagingWindowBannerProps {
  status: "human_agent" | "closed"
  channelType: ChannelType
  humanAgentExpiresAt: string | null
  /** Abre el selector de plantillas; sin él no se muestra el botón. */
  onOpenTemplates?: () => void
}

/**
 * Aviso sobre el composer cuando pasaron más de 24 h desde el último mensaje
 * del contacto: explica qué se puede hacer según el canal.
 */
export function MessagingWindowBanner({ status, channelType, humanAgentExpiresAt, onOpenTemplates }: MessagingWindowBannerProps) {
  const { t, language } = useTranslation()
  const locale = language === "en" ? enUS : es

  if (status === "human_agent") {
    const when = humanAgentExpiresAt ? format(new Date(humanAgentExpiresAt), "EEE d MMM, HH:mm", { locale }) : ""
    return (
      <div className="px-4 pt-3 flex items-center gap-2 text-sm text-muted-foreground" role="status">
        <Clock className="h-4 w-4 shrink-0" />
        <span className="flex-1">{t("chats.windowHumanAgent", { when })}</span>
      </div>
    )
  }

  if (channelType === ChannelType.WHATSAPP) {
    return (
      <div className="px-4 pt-3 flex items-center gap-2 text-sm" role="status">
        <AlertTriangle className="h-4 w-4 shrink-0 text-warning" />
        <span className="flex-1">{t("chats.windowClosedWhatsApp")}</span>
        {onOpenTemplates && (
          <Button size="sm" variant="outline" className="shrink-0" onClick={onOpenTemplates}>
            {t("chats.windowSendTemplate")}
          </Button>
        )}
      </div>
    )
  }

  const channel = channelType === ChannelType.INSTAGRAM ? "Instagram" : "Messenger"
  return (
    <div className="px-4 pt-3 flex items-center gap-2 text-sm" role="status">
      <AlertTriangle className="h-4 w-4 shrink-0 text-warning" />
      <span className="flex-1">{t("chats.windowClosedMeta", { channel })}</span>
    </div>
  )
}
