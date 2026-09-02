"use client"

import { useEffect, useState } from "react"
import { Loader2, Users } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Channel } from "@/data/types"
import { ChannelType } from "@/data/enums"
import { createWhatsAppGroup } from "@/lib/api/whatsapp-groups"
import { useToast } from "@/components/Toast"
import { GroupEligibilityGate, GroupEligibilityNotice } from "./GroupEligibilityGate"

interface NewGroupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channels: Channel[]
  defaultChannelId?: number
  opportunityId?: number
  onCreated: (groupId: number) => Promise<void> | void
}

export function NewGroupDialog({ open, onOpenChange, channels, defaultChannelId, opportunityId, onCreated }: NewGroupDialogProps) {
  const { addToast } = useToast()
  const whatsAppChannels = channels.filter((c) => c.type === ChannelType.WHATSAPP && c.status === "active")

  const [channelId, setChannelId] = useState<number | null>(defaultChannelId ?? whatsAppChannels[0]?.id ?? null)
  const [subject, setSubject] = useState("")
  const [description, setDescription] = useState("")
  const [joinApprovalMode, setJoinApprovalMode] = useState<"approval_required" | "auto_approve">("approval_required")
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    if (open) {
      setChannelId(defaultChannelId ?? whatsAppChannels[0]?.id ?? null)
      setSubject("")
      setDescription("")
      setJoinApprovalMode("approval_required")
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, defaultChannelId])

  const handleSubmit = async () => {
    if (!channelId || !subject.trim()) return
    try {
      setIsSubmitting(true)
      const group = await createWhatsAppGroup({
        channel_id: channelId,
        subject: subject.trim(),
        description: description.trim() || undefined,
        join_approval_mode: joinApprovalMode,
        opportunity_id: opportunityId,
      })
      addToast({ type: "success", title: "Grupo en creación", description: "WhatsApp va a confirmar la creación en unos segundos." })
      await onCreated(group.id)
      onOpenChange(false)
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudo crear el grupo" })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Users className="h-5 w-5" />
            Nuevo grupo de WhatsApp
          </DialogTitle>
          <DialogDescription>
            Meta limita los grupos a 8 participantes (incluido tu número). Pensalo como un grupo chico para una operación puntual, no para difusión masiva.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="group-channel">Canal</Label>
            <Select value={channelId ? String(channelId) : undefined} onValueChange={(v) => setChannelId(Number(v))}>
              <SelectTrigger id="group-channel">
                <SelectValue placeholder="Elegí un canal de WhatsApp" />
              </SelectTrigger>
              <SelectContent>
                {whatsAppChannels.map((channel) => (
                  <SelectItem key={channel.id} value={String(channel.id)}>
                    {channel.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {whatsAppChannels.length === 0 && (
              <p className="text-xs text-destructive">No hay canales de WhatsApp activos.</p>
            )}
          </div>

          {channelId && (
            <GroupEligibilityGate channelId={channelId}>
              {(eligibility, isLoading) =>
                !isLoading && eligibility && eligibility.status !== "eligible" ? (
                  <GroupEligibilityNotice eligibility={eligibility} />
                ) : null
              }
            </GroupEligibilityGate>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="group-subject">Nombre del grupo</Label>
            <Input
              id="group-subject"
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              maxLength={128}
              placeholder="Venta Juan Pérez"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="group-description">Descripción (opcional)</Label>
            <Textarea
              id="group-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              maxLength={2048}
              rows={2}
              placeholder="Contexto breve para quien recibe la invitación"
            />
          </div>

          <div className="space-y-1.5">
            <Label>Ingreso al grupo</Label>
            <RadioGroup value={joinApprovalMode} onValueChange={(v) => setJoinApprovalMode(v as typeof joinApprovalMode)}>
              <div className="flex items-center gap-2">
                <RadioGroupItem value="approval_required" id="mode-approval" />
                <Label htmlFor="mode-approval" className="font-normal">Requiere tu aprobación</Label>
              </div>
              <div className="flex items-center gap-2">
                <RadioGroupItem value="auto_approve" id="mode-auto" />
                <Label htmlFor="mode-auto" className="font-normal">Automático</Label>
              </div>
            </RadioGroup>
          </div>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button onClick={() => void handleSubmit()} disabled={isSubmitting || !channelId || !subject.trim()}>
            {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Crear grupo
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
