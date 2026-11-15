"use client"

import { useEffect, useState } from "react"
import { Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import type { WhatsAppFlow, WhatsAppFlowSendDefaults } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { updateFlow } from "@/lib/api/whatsapp-flows"

/** Mismos límites que Meta para el mensaje interactivo de flow. */
const BODY_MAX = 1024
const CTA_MAX = 30
const HEADER_FOOTER_MAX = 60

interface FlowSendMessageFormProps {
  flow: WhatsAppFlow
  onSaved: (flow: WhatsAppFlow) => void
  /** Avisa si hay cambios sin guardar respecto de `flow`. */
  onDirtyChange?: (dirty: boolean) => void
  /** Recibe los textos tal como están en pantalla, guardados o no. */
  onValuesChange?: (values: WhatsAppFlowSendDefaults) => void
}

const toSendDefaults = (header: string, body: string, footer: string, cta: string): WhatsAppFlowSendDefaults => ({
  header: header.trim() || null,
  body: body.trim(),
  footer: footer.trim() || null,
  cta: cta.trim(),
})

/** Mensaje de envío: los textos con los que el chat y las automatizaciones mandan el formulario con la ventana abierta. */
export function FlowSendMessageForm({ flow, onSaved, onDirtyChange, onValuesChange }: FlowSendMessageFormProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const stored = flow.send_defaults
  const [header, setHeader] = useState(stored.header ?? "")
  const [body, setBody] = useState(stored.body)
  const [footer, setFooter] = useState(stored.footer ?? "")
  const [cta, setCta] = useState(stored.cta)
  const [saving, setSaving] = useState(false)

  const dirty =
    header.trim() !== (stored.header ?? "") ||
    body.trim() !== stored.body ||
    footer.trim() !== (stored.footer ?? "") ||
    cta.trim() !== stored.cta
  const canSave = dirty && !saving && body.trim() !== "" && cta.trim() !== ""

  useEffect(() => {
    if (!onDirtyChange) return
    onDirtyChange(dirty)
    return () => onDirtyChange(false)
  }, [dirty, onDirtyChange])

  useEffect(() => {
    onValuesChange?.(toSendDefaults(header, body, footer, cta))
  }, [header, body, footer, cta, onValuesChange])

  const save = async () => {
    if (!canSave) return
    setSaving(true)
    try {
      const saved = await updateFlow(flow.id, { send_defaults: toSendDefaults(header, body, footer, cta) })
      onSaved(saved)
      addToast({ type: "success", title: t("flows.journey.opening.messageSaved") })
    } catch (error) {
      addToast({
        type: "error",
        title: t("flows.journey.opening.messageSaveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setSaving(false)
    }
  }

  const id = `flow-${flow.id}-send`

  return (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <Label htmlFor={`${id}-header`}>{t("chats.flows.header")}</Label>
        <Input id={`${id}-header`} value={header} maxLength={HEADER_FOOTER_MAX} onChange={(event) => setHeader(event.target.value)} />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`${id}-body`}>{t("chats.flows.body")}</Label>
        <Textarea
          id={`${id}-body`}
          value={body}
          maxLength={BODY_MAX}
          rows={3}
          aria-invalid={body.trim() === ""}
          onChange={(event) => setBody(event.target.value)}
        />
        <p className="text-right text-xs text-muted-foreground">{body.length}/{BODY_MAX}</p>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`${id}-footer`}>{t("chats.flows.footer")}</Label>
        <Input id={`${id}-footer`} value={footer} maxLength={HEADER_FOOTER_MAX} onChange={(event) => setFooter(event.target.value)} />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`${id}-cta`}>{t("chats.flows.cta")}</Label>
        <Input
          id={`${id}-cta`}
          value={cta}
          maxLength={CTA_MAX}
          aria-invalid={cta.trim() === ""}
          onChange={(event) => setCta(event.target.value)}
        />
        <p className="text-right text-xs text-muted-foreground">{cta.length}/{CTA_MAX}</p>
      </div>
      <div className="flex justify-end">
        <Button type="button" size="sm" onClick={() => void save()} disabled={!canSave}>
          {saving ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : null}
          {t("flows.journey.opening.saveMessage")}
        </Button>
      </div>
    </div>
  )
}
