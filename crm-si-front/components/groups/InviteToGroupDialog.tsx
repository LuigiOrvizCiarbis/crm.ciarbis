"use client"

import { useEffect, useState } from "react"
import { Loader2, Send, Plus, X, AlertTriangle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { getGroupInviteTemplates, inviteToGroup, type WhatsAppGroupInviteTemplate } from "@/lib/api/whatsapp-groups"
import { useToast } from "@/components/Toast"

interface InviteToGroupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  groupId: number
  availableSlots: number
  onInvited?: () => void
}

interface InviteeDraft {
  phone: string
  name: string
}

export function InviteToGroupDialog({ open, onOpenChange, groupId, availableSlots, onInvited }: InviteToGroupDialogProps) {
  const { addToast } = useToast()
  const [templates, setTemplates] = useState<WhatsAppGroupInviteTemplate[]>([])
  const [isLoadingTemplates, setIsLoadingTemplates] = useState(false)
  const [templateId, setTemplateId] = useState<number | null>(null)
  const [invitees, setInvitees] = useState<InviteeDraft[]>([{ phone: "", name: "" }])
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    if (!open) return
    setIsLoadingTemplates(true)
    getGroupInviteTemplates(groupId)
      .then((data) => {
        setTemplates(data)
        setTemplateId(data[0]?.id ?? null)
      })
      .catch(() => setTemplates([]))
      .finally(() => setIsLoadingTemplates(false))
    setInvitees([{ phone: "", name: "" }])
  }, [open, groupId])

  const updateInvitee = (index: number, patch: Partial<InviteeDraft>) => {
    setInvitees((prev) => prev.map((invitee, i) => (i === index ? { ...invitee, ...patch } : invitee)))
  }

  const addInviteeRow = () => {
    if (invitees.length >= availableSlots) return
    setInvitees((prev) => [...prev, { phone: "", name: "" }])
  }

  const removeInviteeRow = (index: number) => {
    setInvitees((prev) => prev.filter((_, i) => i !== index))
  }

  const validInvitees = invitees.filter((i) => i.phone.trim().length > 0)

  const handleSubmit = async () => {
    if (!templateId || validInvitees.length === 0) return
    try {
      setIsSubmitting(true)
      await inviteToGroup(groupId, {
        template_id: templateId,
        invitees: validInvitees.map((i) => ({ phone: i.phone.trim(), name: i.name.trim() || undefined })),
      })
      addToast({ type: "success", title: "Invitaciones enviadas" })
      onInvited?.()
      onOpenChange(false)
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudieron enviar las invitaciones" })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Send className="h-5 w-5" />
            Invitar al grupo
          </DialogTitle>
          <DialogDescription>
            Cada invitado recibe el link de ingreso por un mensaje de plantilla. Quedan {availableSlots} lugares disponibles.
          </DialogDescription>
        </DialogHeader>

        {isLoadingTemplates ? (
          <div className="flex items-center justify-center py-8 text-muted-foreground">
            <Loader2 className="h-5 w-5 animate-spin" />
          </div>
        ) : templates.length === 0 ? (
          <div className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <p>
              Todavía no tenés una plantilla de &quot;Group invite link&quot; aprobada. Creala desde la Template Library en el
              Business Manager de Meta y sincronizá las plantillas del canal.
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="invite-template">Plantilla</Label>
              <Select value={templateId ? String(templateId) : undefined} onValueChange={(v) => setTemplateId(Number(v))}>
                <SelectTrigger id="invite-template">
                  <SelectValue placeholder="Elegí una plantilla" />
                </SelectTrigger>
                <SelectContent>
                  {templates.map((template) => (
                    <SelectItem key={template.id} value={String(template.id)}>
                      {template.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Invitados</Label>
              {invitees.map((invitee, index) => (
                <div key={index} className="flex items-center gap-2">
                  <Input
                    value={invitee.phone}
                    onChange={(e) => updateInvitee(index, { phone: e.target.value })}
                    placeholder="+54 9 11 0000-0000"
                    className="flex-1"
                  />
                  <Input
                    value={invitee.name}
                    onChange={(e) => updateInvitee(index, { name: e.target.value })}
                    placeholder="Nombre (opcional)"
                    className="flex-1"
                  />
                  {invitees.length > 1 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 shrink-0 p-0 text-muted-foreground"
                      onClick={() => removeInviteeRow(index)}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              ))}
              {invitees.length < availableSlots && (
                <Button variant="ghost" size="sm" className="gap-1" onClick={addInviteeRow}>
                  <Plus className="h-4 w-4" />
                  Agregar invitado
                </Button>
              )}
            </div>
          </div>
        )}

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button
            onClick={() => void handleSubmit()}
            disabled={isSubmitting || !templateId || validInvitees.length === 0 || templates.length === 0}
          >
            {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Enviar invitación{validInvitees.length > 1 ? "es" : ""}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
