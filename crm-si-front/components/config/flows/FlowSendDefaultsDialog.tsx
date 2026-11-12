"use client"

import { useState } from "react"
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
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import type { WhatsAppFlow } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { updateFlow } from "@/lib/api/whatsapp-flows"

/** Mismos límites que Meta para el mensaje interactivo de flow. */
const BODY_MAX = 1024
const CTA_MAX = 30
const HEADER_FOOTER_MAX = 60

interface FlowSendDefaultsDialogProps {
  flow: WhatsAppFlow | null
  onOpenChange: (open: boolean) => void
  onSaved: (flow: WhatsAppFlow) => void
}

/** Textos con los que el chat precarga el envío de este formulario. */
export function FlowSendDefaultsDialog({ flow, onOpenChange, onSaved }: FlowSendDefaultsDialogProps) {
  const [saving, setSaving] = useState(false)

  return (
    <Dialog open={flow !== null} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-w-lg">
        {flow && (
          <SendDefaultsForm
            key={flow.id}
            flow={flow}
            saving={saving}
            onSavingChange={setSaving}
            onClose={() => onOpenChange(false)}
            onSaved={onSaved}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

interface SendDefaultsFormProps {
  flow: WhatsAppFlow
  saving: boolean
  onSavingChange: (saving: boolean) => void
  onClose: () => void
  onSaved: (flow: WhatsAppFlow) => void
}

function SendDefaultsForm({ flow, saving, onSavingChange, onClose, onSaved }: SendDefaultsFormProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [header, setHeader] = useState(flow.send_defaults.header ?? "")
  const [body, setBody] = useState(flow.send_defaults.body)
  const [footer, setFooter] = useState(flow.send_defaults.footer ?? "")
  const [cta, setCta] = useState(flow.send_defaults.cta)

  const canSave = !saving && !!body.trim() && !!cta.trim()

  const save = async () => {
    if (!canSave) return
    onSavingChange(true)
    try {
      const saved = await updateFlow(flow.id, {
        send_defaults: {
          header: header.trim() || null,
          body: body.trim(),
          footer: footer.trim() || null,
          cta: cta.trim(),
        },
      })
      onSaved(saved)
      addToast({ type: "success", title: t("flows.settings.sendDefaults.saved") })
      onClose()
    } catch (error) {
      addToast({
        type: "error",
        title: t("flows.settings.sendDefaults.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      onSavingChange(false)
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{t("flows.settings.sendDefaults.title", { name: flow.name })}</DialogTitle>
        <DialogDescription>{t("flows.settings.sendDefaults.description")}</DialogDescription>
      </DialogHeader>
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="flow-defaults-header">{t("chats.flows.header")}</Label>
          <Input id="flow-defaults-header" value={header} maxLength={HEADER_FOOTER_MAX} onChange={(event) => setHeader(event.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="flow-defaults-body">{t("chats.flows.body")}</Label>
          <Textarea id="flow-defaults-body" value={body} maxLength={BODY_MAX} rows={4} onChange={(event) => setBody(event.target.value)} />
          <p className="text-right text-xs text-muted-foreground">{body.length}/{BODY_MAX}</p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="flow-defaults-footer">{t("chats.flows.footer")}</Label>
          <Input id="flow-defaults-footer" value={footer} maxLength={HEADER_FOOTER_MAX} onChange={(event) => setFooter(event.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="flow-defaults-cta">{t("chats.flows.cta")}</Label>
          <Input id="flow-defaults-cta" value={cta} maxLength={CTA_MAX} onChange={(event) => setCta(event.target.value)} />
          <p className="text-right text-xs text-muted-foreground">{cta.length}/{CTA_MAX}</p>
        </div>
      </div>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onClose} disabled={saving}>
          {t("common.cancel")}
        </Button>
        <Button type="button" onClick={() => void save()} disabled={!canSave}>
          {saving && <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />}
          {t("common.save")}
        </Button>
      </DialogFooter>
    </>
  )
}
