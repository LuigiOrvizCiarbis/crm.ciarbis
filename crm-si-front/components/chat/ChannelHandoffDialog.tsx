"use client"

import { useMemo, useState } from "react"
import { Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import type { Channel } from "@/data/types"
import { ChannelType } from "@/data/enums"
import type { ChannelHandoffSettings } from "@/lib/api/channels"
import { useTranslation } from "@/hooks/useTranslation"

const NO_RESPONSIBLE = "none"

interface ChannelHandoffDialogProps {
  channel: Channel | null
  onOpenChange: (open: boolean) => void
  onSave: (channelId: number, settings: ChannelHandoffSettings) => Promise<void>
}

/**
 * Configuración de derivaciones a humano de un canal: quién recibe la
 * derivación cuando la conversación no tiene asignado, y si se avisa por
 * WhatsApp al número del canal (sólo números en la app WhatsApp Business).
 */
export function ChannelHandoffDialog({ channel, onOpenChange, onSave }: ChannelHandoffDialogProps) {
  const { t } = useTranslation()

  return (
    <Dialog open={channel !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("chats.handoffSettings.title")}</DialogTitle>
          <DialogDescription>
            {t("chats.handoffSettings.description", { name: channel?.name ?? "" })}
          </DialogDescription>
        </DialogHeader>
        {/* key: el formulario arranca de cero con los valores de cada canal. */}
        {channel && (
          <HandoffSettingsForm key={channel.id} channel={channel} onClose={() => onOpenChange(false)} onSave={onSave} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function HandoffSettingsForm({
  channel,
  onClose,
  onSave,
}: {
  channel: Channel
  onClose: () => void
  onSave: ChannelHandoffDialogProps["onSave"]
}) {
  const { t } = useTranslation()
  const [responsible, setResponsible] = useState(
    channel.handoff_responsible_user_id ? String(channel.handoff_responsible_user_id) : NO_RESPONSIBLE,
  )
  const [alertEnabled, setAlertEnabled] = useState(channel.handoff_whatsapp_alert_enabled ?? true)
  const [saving, setSaving] = useState(false)

  // El responsable tiene que poder atender el canal: su dueño o alguien
  // asignado a él (lo mismo que valida el backend).
  const candidates = useMemo(() => {
    const people = [...(channel.user ? [channel.user] : []), ...(channel.users ?? [])]
    return people.filter((person, index) => people.findIndex((other) => other.id === person.id) === index)
  }, [channel])

  const isWhatsApp = channel.type === ChannelType.WHATSAPP
  const onBusinessApp = channel.whatsapp_config?.is_on_biz_app === true

  const save = async () => {
    if (saving) return
    setSaving(true)
    try {
      await onSave(channel.id, {
        handoff_responsible_user_id: responsible === NO_RESPONSIBLE ? null : Number(responsible),
        ...(isWhatsApp ? { handoff_whatsapp_alert_enabled: alertEnabled } : {}),
      })
      onClose()
    } catch {
      // El error ya lo informa quien guarda; el diálogo queda abierto.
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <div className="space-y-5">
        <div className="space-y-2">
          <Label htmlFor="handoff-responsible">{t("chats.handoffSettings.responsible")}</Label>
          <Select value={responsible} onValueChange={setResponsible}>
            <SelectTrigger id="handoff-responsible">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NO_RESPONSIBLE}>{t("chats.handoffSettings.noResponsible")}</SelectItem>
              {candidates.map((person) => (
                <SelectItem key={person.id} value={String(person.id)}>
                  {person.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">{t("chats.handoffSettings.responsibleHint")}</p>
        </div>

        {isWhatsApp && (
          <div className="flex items-start justify-between gap-4">
            <div className="space-y-1">
              <Label htmlFor="handoff-whatsapp-alert">{t("chats.handoffSettings.whatsappAlert")}</Label>
              <p className="text-xs text-muted-foreground">
                {onBusinessApp
                  ? t("chats.handoffSettings.whatsappAlertHint")
                  : t("chats.handoffSettings.whatsappAlertUnavailable")}
              </p>
            </div>
            <Switch
              id="handoff-whatsapp-alert"
              checked={onBusinessApp && alertEnabled}
              onCheckedChange={setAlertEnabled}
              disabled={!onBusinessApp}
            />
          </div>
        )}
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={onClose} disabled={saving}>
          {t("chats.cancel")}
        </Button>
        <Button onClick={() => void save()} disabled={saving}>
          {saving && <Loader2 className="mr-1.5 size-4 animate-spin" />}
          {t("chats.save")}
        </Button>
      </DialogFooter>
    </>
  )
}
