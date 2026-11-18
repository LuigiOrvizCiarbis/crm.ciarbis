"use client"

import { useEffect, useMemo, useState } from "react"
import { Loader2 } from "lucide-react"
import type { WhatsAppFlow } from "@/data/types"
import { getFlows, type SendFlowInput } from "@/lib/api/whatsapp-flows"
import { templateBodyPreview, templateParameterRows } from "@/lib/template-parameters"
import { ChatBubble, templateBubble } from "@/components/flows/journey/ChatBubble"
import { useManagedTemplates } from "@/components/flows/journey/useManagedTemplates"
import { Button } from "@/components/ui/button"
import { EmojiInput, FormattedTextarea } from "@/components/rich-text/FormattedField"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

const BODY_MAX = 1024
const CTA_MAX = 30
const HEADER_FOOTER_MAX = 60

interface FlowPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
  onSend: (input: SendFlowInput) => Promise<void>
  /** Fuera de la ventana de 24 h el formulario se manda con una de sus plantillas listas. */
  windowClosed: boolean
  disabled?: boolean
}

/** Por qué un formulario no se puede mandar con la ventana cerrada, o null si tiene una plantilla lista. */
function templateBlocker(flow: WhatsAppFlow): "noTemplate" | "templateParameters" | "templateNotApproved" | null {
  if (flow.effective_template_id !== null) return null
  if (flow.send_templates.length === 0) return "noTemplate"
  return flow.send_templates.some((item) => item.issue === "parameters") ? "templateParameters" : "templateNotApproved"
}

export function FlowPickerDialog({ open, onOpenChange, channelId, onSend, windowClosed, disabled = false }: FlowPickerDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [flows, setFlows] = useState<WhatsAppFlow[]>([])
  const [loading, setLoading] = useState(false)
  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [sending, setSending] = useState(false)
  const [flowId, setFlowId] = useState<string>("")
  const [templateId, setTemplateId] = useState<string>("")
  const [body, setBody] = useState("")
  const [cta, setCta] = useState("")
  const [header, setHeader] = useState("")
  const [footer, setFooter] = useState("")

  const contactFieldsStore = useContactFieldsStore()
  const { fetch: fetchContactFields } = contactFieldsStore
  const { templates } = useManagedTemplates(open && windowClosed ? channelId : null)

  const applyFlow = (flow: WhatsAppFlow | undefined) => {
    setFlowId(flow ? String(flow.id) : "")
    setTemplateId(flow?.effective_template_id ? String(flow.effective_template_id) : "")
    setHeader(flow?.send_defaults.header ?? "")
    setBody(flow?.send_defaults.body ?? "")
    setFooter(flow?.send_defaults.footer ?? "")
    setCta(flow?.send_defaults.cta ?? "")
  }

  useEffect(() => {
    if (!open) return
    if (windowClosed) void fetchContactFields()
    setLoading(true)
    setLoadFailed(false)
    getFlows(channelId, { sendable: true })
      .then((items) => {
        setFlows(items)
        // Una sola opción usable: queda elegida y lista para enviar.
        const usable = windowClosed ? items.filter((item) => templateBlocker(item) === null) : items
        applyFlow(usable.length === 1 ? usable[0] : undefined)
      })
      .catch(() => {
        setFlows([])
        setLoadFailed(true)
      })
      .finally(() => setLoading(false))
  }, [open, channelId, reloadKey, windowClosed, fetchContactFields])

  const selectedFlow = flows.find((flow) => String(flow.id) === flowId)
  const readyTemplates = selectedFlow?.send_templates.filter((item) => item.ready) ?? []
  const fullTemplate = templates?.find((template) => String(template.id) === templateId)
  const context = useMemo(() => ({ answers: [], contactFields: contactFieldsStore.fields }), [contactFieldsStore.fields])

  const canSend = windowClosed
    ? !!flowId && !!templateId && !disabled && !sending
    : !!flowId && !!body.trim() && !!cta.trim() && !disabled && !sending

  const submit = async () => {
    if (!canSend) return
    setSending(true)
    try {
      await onSend(
        windowClosed
          ? { whatsapp_flow_id: Number(flowId), template_id: Number(templateId) }
          : {
              whatsapp_flow_id: Number(flowId),
              body: body.trim(),
              cta: cta.trim(),
              ...(header.trim() ? { header: header.trim() } : {}),
              ...(footer.trim() ? { footer: footer.trim() } : {}),
            },
      )
      applyFlow(undefined)
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
        <DialogDescription>{windowClosed ? t("chats.flows.closedDescription") : t("chats.flows.description")}</DialogDescription>
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
            <Select value={flowId} onValueChange={(value) => applyFlow(flows.find((flow) => String(flow.id) === value))}>
              <SelectTrigger id="flow-picker-flow" className="w-full"><SelectValue placeholder={t("chats.flows.selectFlow")} /></SelectTrigger>
              <SelectContent>
                {flows.map((flow) => {
                  const blocker = windowClosed ? templateBlocker(flow) : null
                  return (
                    <SelectItem key={flow.id} value={String(flow.id)} disabled={blocker !== null}>
                      {flow.name}{flow.status !== "PUBLISHED" ? ` (${flow.status})` : ""}
                      {blocker ? <span className="text-xs text-muted-foreground"> · {t(`chats.flows.blockers.${blocker}`)}</span> : null}
                    </SelectItem>
                  )
                })}
              </SelectContent>
            </Select>
            {flowId && !windowClosed ? <p className="text-xs text-muted-foreground">{t("chats.flows.defaultsHint")}</p> : null}
          </div>
          {windowClosed ? (
            selectedFlow ? (
              <>
                <div className="space-y-1.5">
                  <Label htmlFor="flow-picker-template">{t("chats.flows.template")}</Label>
                  <Select value={templateId} onValueChange={setTemplateId}>
                    <SelectTrigger id="flow-picker-template" className="w-full"><SelectValue placeholder={t("chats.flows.selectTemplate")} /></SelectTrigger>
                    <SelectContent>
                      {readyTemplates.map((item) => (
                        <SelectItem key={item.id} value={String(item.id)}>
                          {item.name} · {item.language}
                          {item.id === selectedFlow.effective_template_id ? ` · ${t("chats.flows.defaultTemplate")}` : ""}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {fullTemplate ? (
                  <div className="space-y-1.5">
                    <p className="text-xs font-medium text-muted-foreground">{t("chats.flows.templatePreview")}</p>
                    <div className="rounded-lg bg-[#efeae2] p-3 dark:bg-muted/40">
                      <ChatBubble
                        content={templateBubble(
                          fullTemplate,
                          templateBodyPreview(
                            fullTemplate,
                            templateParameterRows(fullTemplate, selectedFlow.opening.templates[templateId]?.parameters ?? [], context),
                            context,
                            t,
                          ),
                        )}
                      />
                    </div>
                  </div>
                ) : null}
              </>
            ) : (
              <p className="text-xs text-muted-foreground">{t("chats.flows.closedHint")}</p>
            )
          ) : (
            <>
              {selectedFlow?.send_defaults.image ? (
                // Meta admite un solo encabezado: con la imagen de la apertura no va el de texto.
                <p className="text-xs text-muted-foreground">{t("chats.flows.withImage")}</p>
              ) : (
                <div className="space-y-1.5">
                  <Label htmlFor="flow-picker-header">{t("chats.flows.header")}</Label>
                  <EmojiInput id="flow-picker-header" value={header} maxLength={HEADER_FOOTER_MAX} onValueChange={setHeader} />
                </div>
              )}
              <div className="space-y-1.5">
                <Label htmlFor="flow-picker-body">{t("chats.flows.body")}</Label>
                <FormattedTextarea id="flow-picker-body" dialect="whatsapp" value={body} maxLength={BODY_MAX} rows={4} onValueChange={setBody} />
                <p className="text-right text-xs text-muted-foreground">{body.length}/{BODY_MAX}</p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="flow-picker-footer">{t("chats.flows.footer")}</Label>
                <EmojiInput id="flow-picker-footer" value={footer} maxLength={HEADER_FOOTER_MAX} onValueChange={setFooter} />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="flow-picker-cta">{t("chats.flows.cta")}</Label>
                <EmojiInput id="flow-picker-cta" value={cta} maxLength={CTA_MAX} onValueChange={setCta} />
                <p className="text-right text-xs text-muted-foreground">{cta.length}/{CTA_MAX}</p>
              </div>
            </>
          )}
        </div>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={() => onOpenChange(false)} disabled={sending}>{t("chats.flows.cancel")}</Button>
        <Button disabled={!canSend} onClick={() => void submit()}>{sending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}{t("chats.flows.submit")}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
}
