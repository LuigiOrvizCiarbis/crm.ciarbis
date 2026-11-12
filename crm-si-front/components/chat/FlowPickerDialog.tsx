"use client"

import { useEffect, useState } from "react"
import { Loader2 } from "lucide-react"
import type { WhatsAppFlow } from "@/data/types"
import { getFlows, type SendFlowInput } from "@/lib/api/whatsapp-flows"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"

const BODY_MAX = 1024
const CTA_MAX = 30
const HEADER_FOOTER_MAX = 60

interface FlowPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
  onSend: (input: SendFlowInput) => Promise<void>
  disabled?: boolean
}

export function FlowPickerDialog({ open, onOpenChange, channelId, onSend, disabled = false }: FlowPickerDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [flows, setFlows] = useState<WhatsAppFlow[]>([])
  const [loading, setLoading] = useState(false)
  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [sending, setSending] = useState(false)
  const [flowId, setFlowId] = useState<string>("")
  const [body, setBody] = useState("")
  const [cta, setCta] = useState("")
  const [header, setHeader] = useState("")
  const [footer, setFooter] = useState("")

  useEffect(() => {
    if (!open) return
    setLoading(true)
    setLoadFailed(false)
    getFlows(channelId, { sendable: true })
      .then((items) => {
        setFlows(items)
        setFlowId((current) => items.some((flow) => String(flow.id) === current) ? current : "")
      })
      .catch(() => {
        setFlows([])
        setLoadFailed(true)
      })
      .finally(() => setLoading(false))
  }, [open, channelId, reloadKey])

  useEffect(() => {
    if (open) setCta((current) => current || t("chats.flows.ctaDefault"))
  }, [open, t])

  const reset = () => {
    setFlowId("")
    setBody("")
    setCta("")
    setHeader("")
    setFooter("")
  }

  const canSend = !!flowId && !!body.trim() && !!cta.trim() && !disabled && !sending

  const submit = async () => {
    if (!canSend) return
    setSending(true)
    try {
      await onSend({
        whatsapp_flow_id: Number(flowId),
        body: body.trim(),
        cta: cta.trim(),
        ...(header.trim() ? { header: header.trim() } : {}),
        ...(footer.trim() ? { footer: footer.trim() } : {}),
      })
      reset()
      onOpenChange(false)
    } catch (error) {
      addToast({ type: "error", title: t("chats.flows.sendError"), description: error instanceof Error ? error.message : undefined })
    } finally {
      setSending(false)
    }
  }

  return <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="max-w-lg">
      <DialogHeader>
        <DialogTitle>{t("chats.flows.send")}</DialogTitle>
        <DialogDescription>{t("chats.flows.description")}</DialogDescription>
      </DialogHeader>
      {loading ? (
        <div role="status" aria-label={t("chats.flows.loading")} className="flex justify-center p-8"><Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" /></div>
      ) : loadFailed ? (
        <div className="flex flex-col items-center gap-3 p-8 text-center text-sm text-muted-foreground">
          <p>{t("chats.flows.loadError")}</p>
          <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>{t("chats.flows.retry")}</Button>
        </div>
      ) : flows.length === 0 ? (
        <p className="p-8 text-center text-sm text-muted-foreground">{t("chats.flows.empty")}</p>
      ) : (
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="flow-picker-flow">{t("chats.flows.flow")}</Label>
            <Select value={flowId} onValueChange={setFlowId}>
              <SelectTrigger id="flow-picker-flow" className="w-full"><SelectValue placeholder={t("chats.flows.selectFlow")} /></SelectTrigger>
              <SelectContent>
                {flows.map((flow) => <SelectItem key={flow.id} value={String(flow.id)}>{flow.name}{flow.status !== "PUBLISHED" ? ` (${flow.status})` : ""}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="flow-picker-header">{t("chats.flows.header")}</Label>
            <Input id="flow-picker-header" value={header} maxLength={HEADER_FOOTER_MAX} onChange={(event) => setHeader(event.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="flow-picker-body">{t("chats.flows.body")}</Label>
            <Textarea id="flow-picker-body" value={body} maxLength={BODY_MAX} rows={4} onChange={(event) => setBody(event.target.value)} />
            <p className="text-right text-xs text-muted-foreground">{body.length}/{BODY_MAX}</p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="flow-picker-footer">{t("chats.flows.footer")}</Label>
            <Input id="flow-picker-footer" value={footer} maxLength={HEADER_FOOTER_MAX} onChange={(event) => setFooter(event.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="flow-picker-cta">{t("chats.flows.cta")}</Label>
            <Input id="flow-picker-cta" value={cta} maxLength={CTA_MAX} onChange={(event) => setCta(event.target.value)} />
            <p className="text-right text-xs text-muted-foreground">{cta.length}/{CTA_MAX}</p>
          </div>
        </div>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={() => onOpenChange(false)} disabled={sending}>{t("chats.flows.cancel")}</Button>
        <Button disabled={!canSend} onClick={() => void submit()}>{sending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}{t("chats.flows.submit")}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
}
