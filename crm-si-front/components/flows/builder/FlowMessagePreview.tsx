"use client"

import { useEffect, useState } from "react"
import { FileText, Image as ImageIcon, ListChecks, MessageSquarePlus, Pencil, Video } from "lucide-react"

import { NewTemplateDialog, canUseFlowInTemplate, flowTemplateDraft, type TemplateDraft } from "@/components/broadcasts/NewTemplateDialog"
import { FlowSendDefaultsDialog } from "@/components/config/flows/FlowSendDefaultsDialog"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import type { TemplateComponent, WhatsAppFlow, WhatsAppTemplate } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { getManagedTemplates } from "@/lib/api/templates"
import { isRepeaterScreen } from "@/lib/flow-builder/repeater"
import type { FlowDefinition, TextBlock } from "@/lib/flow-builder/types"

import { PhoneFrame } from "./PhoneFrame"

/** Lo que el constructor necesita para mostrar el mensaje que abre el formulario. */
export interface FlowMessageContext {
  /** null mientras el formulario no se guardó. */
  flow: WhatsAppFlow | null
  channelId: number | null
  onSendDefaultsSaved: (flow: WhatsAppFlow) => void
}

type InvitationHeader = { kind: "text"; text: string } | { kind: "image"; url: string | null } | { kind: "video" } | { kind: "document" }

interface InvitationMessage {
  header: InvitationHeader | null
  body: string
  footer: string | null
  cta: string
}

// Espejo de WhatsAppFlow::sendDefaults() para un formulario sin guardar.
const DEFAULT_SEND_BODY = "Tocá el botón para completar el formulario."
const DEFAULT_SEND_CTA = "Completar"

type TemplateButton = NonNullable<TemplateComponent["buttons"]>[number]
type TemplateStatusKey = "approved" | "pending" | "rejected" | "other"

const STATUS_KEY: Partial<Record<WhatsAppTemplate["status"], TemplateStatusKey>> = {
  APPROVED: "approved",
  PENDING: "pending",
  IN_APPEAL: "pending",
  REJECTED: "rejected",
}

/** Botón que abre este formulario; como el backend, usa flow_id y, si falta, el nombre del formulario. */
function flowButtonOf(template: WhatsAppTemplate, metaFlowId: string, flowName: string): TemplateButton | undefined {
  const buttons = template.components.find((component) => component.type.toUpperCase() === "BUTTONS")?.buttons ?? []
  return buttons.find(
    (button) => button.type.toUpperCase() === "FLOW" && (button.flow_id ? String(button.flow_id) === metaFlowId : button.flow_name === flowName),
  )
}

function templateMessage(template: WhatsAppTemplate, button: TemplateButton): InvitationMessage {
  const part = (type: string) => template.components.find((component) => component.type.toUpperCase() === type)
  const header = part("HEADER")
  const format = header?.format?.toUpperCase()
  const example = header?.example?.header_handle?.[0]
  return {
    header:
      format === "TEXT" && header?.text
        ? { kind: "text", text: header.text }
        : format === "IMAGE"
          ? { kind: "image", url: example?.startsWith("http") ? example : null }
          : format === "VIDEO"
            ? { kind: "video" }
            : format === "DOCUMENT"
              ? { kind: "document" }
              : null,
    body: part("BODY")?.text ?? "",
    footer: part("FOOTER")?.text ?? null,
    cta: button.text,
  }
}

function sendMessage(flow: WhatsAppFlow | null, definition: FlowDefinition): InvitationMessage {
  if (flow) {
    const { header, body, footer, cta } = flow.send_defaults
    return { header: header ? { kind: "text", text: header } : null, body, footer, cta }
  }
  const entry = definition.screens[0]
  const heading = entry && !isRepeaterScreen(entry)
    ? entry.blocks.find((block): block is TextBlock => block.type === "heading" && block.text.trim() !== "")
    : undefined
  return { header: null, body: heading?.text ?? DEFAULT_SEND_BODY, footer: null, cta: DEFAULT_SEND_CTA }
}

interface FlowMessagePreviewProps extends FlowMessageContext {
  definition: FlowDefinition
  onOpenForm: () => void
}

/** Mensaje de WhatsApp que abre el formulario: el de envío del CRM o una plantilla con su botón. */
export function FlowMessagePreview({ flow, channelId, definition, onSendDefaultsSaved, onOpenForm }: FlowMessagePreviewProps) {
  const { t } = useTranslation()
  const canCreateTemplate = usePermission("templates.create")
  const [source, setSource] = useState<"send" | number>("send")
  const [editingDefaults, setEditingDefaults] = useState(false)
  const [templateDraft, setTemplateDraft] = useState<{ key: string; draft: TemplateDraft } | null>(null)
  const [reload, setReload] = useState(0)
  const [linked, setLinked] = useState<{
    key: string
    items: Array<{ template: WhatsAppTemplate; button: TemplateButton }>
    failed: boolean
  } | null>(null)

  const metaFlowId = flow?.meta_flow_id ?? null
  const flowName = flow?.name ?? null
  const linkKey = metaFlowId && channelId !== null ? `${channelId}:${metaFlowId}:${flowName}:${reload}` : null

  useEffect(() => {
    if (linkKey === null || channelId === null || metaFlowId === null || flowName === null) return
    let cancelled = false
    getManagedTemplates(channelId)
      .then((templates) => {
        if (cancelled) return
        const items = templates.flatMap((template) => {
          const button = flowButtonOf(template, metaFlowId, flowName)
          return button ? [{ template, button }] : []
        })
        setLinked({ key: linkKey, items, failed: false })
      })
      .catch(() => !cancelled && setLinked({ key: linkKey, items: [], failed: true }))
    return () => {
      cancelled = true
    }
  }, [linkKey, channelId, metaFlowId, flowName])

  const current = linked?.key === linkKey ? linked : null
  const templates = current?.items ?? null
  const retired = flow?.status === "DEPRECATED" || flow?.status === "BLOCKED"
  const selected = source === "send" ? undefined : templates?.find((item) => item.template.id === source)
  const message = selected ? templateMessage(selected.template, selected.button) : sendMessage(flow, definition)

  return (
    <div className="space-y-3">
      <PhoneFrame>
        <ChatScreen message={message} onOpenForm={onOpenForm} />
      </PhoneFrame>

      <div className="space-y-2 rounded-lg border border-border p-3">
        <p className="text-xs font-medium text-foreground">{t("flows.builder.message.source")}</p>
        {flow ? (
          <Select value={selected ? String(selected.template.id) : "send"} onValueChange={(value) => setSource(value === "send" ? "send" : Number(value))}>
            <SelectTrigger className="w-full" aria-label={t("flows.builder.message.source")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="send">{t("flows.builder.message.sendSource")}</SelectItem>
              {(templates ?? []).map(({ template }) => (
                <SelectItem key={template.id} value={String(template.id)}>
                  {t("flows.builder.message.templateSource", {
                    name: template.name,
                    status: t(`flows.builder.message.status.${STATUS_KEY[template.status] ?? "other"}`),
                  })}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : null}
        <p className="text-xs text-muted-foreground">
          {selected ? t("flows.builder.message.templateHint") : t("flows.builder.message.sendHint")}
        </p>
        {flow && channelId === null ? <p className="text-xs text-muted-foreground">{t("flows.builder.message.noChannel")}</p> : null}
        {linkKey !== null && current === null ? <p className="text-xs text-muted-foreground">{t("flows.builder.message.loading")}</p> : null}
        {current?.failed ? (
          <p className="text-xs text-destructive">
            {t("flows.builder.message.loadError")}{" "}
            <button type="button" className="underline underline-offset-2" onClick={() => setReload((value) => value + 1)}>
              {t("flows.builder.message.retry")}
            </button>
          </p>
        ) : null}

        {!flow ? (
          <p className="text-xs text-muted-foreground">{t("flows.builder.message.saveFirst")}</p>
        ) : selected ? null : (
          <div className="flex flex-wrap gap-2 pt-1">
            <Button type="button" size="sm" variant="outline" onClick={() => setEditingDefaults(true)}>
              <Pencil className="size-3.5" /> {t("flows.builder.message.edit")}
            </Button>
            {canCreateTemplate ? (
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={!canUseFlowInTemplate(flow) || channelId === null}
                onClick={() => setTemplateDraft({ key: `${flow.id}-${Date.now()}`, draft: flowTemplateDraft(flow) })}
              >
                <MessageSquarePlus className="size-3.5" /> {t("flows.builder.message.createTemplate")}
              </Button>
            ) : null}
            {canCreateTemplate && !canUseFlowInTemplate(flow) ? (
              <p className="w-full text-xs text-muted-foreground">
                {retired ? t("flows.builder.message.retired") : t("flows.builder.message.publishFirst")}
              </p>
            ) : null}
          </div>
        )}
      </div>

      <FlowSendDefaultsDialog
        flow={editingDefaults ? flow : null}
        onOpenChange={(open) => !open && setEditingDefaults(false)}
        onSaved={onSendDefaultsSaved}
      />
      {templateDraft && channelId !== null ? (
        <NewTemplateDialog
          key={templateDraft.key}
          open
          onOpenChange={(open) => !open && setTemplateDraft(null)}
          channelId={channelId}
          draft={templateDraft.draft}
          onCreated={async () => setReload((value) => value + 1)}
        />
      ) : null}
    </div>
  )
}

function ChatScreen({ message, onOpenForm }: { message: InvitationMessage; onOpenForm: () => void }) {
  const { t } = useTranslation()
  const { header } = message
  return (
    <>
      <div className="flex items-center gap-2 bg-[#008069] px-4 pb-3 pt-5 text-white">
        <span className="size-7 shrink-0 rounded-full bg-white/30" aria-hidden />
        <p className="min-w-0 truncate text-[15px] font-semibold">{t("flows.builder.message.chatTitle")}</p>
      </div>
      <div className="flex-1 overflow-y-auto bg-[#efeae2] p-3">
        <div className="max-w-[88%] overflow-hidden rounded-lg rounded-tl-none bg-white text-[13px] shadow-sm">
          {header?.kind === "image" ? (
            header.url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={header.url} alt="" className="aspect-[1.91/1] w-full object-cover" />
            ) : (
              <div className="flex aspect-[1.91/1] w-full items-center justify-center bg-neutral-200 text-neutral-500">
                <ImageIcon className="size-6" aria-hidden />
              </div>
            )
          ) : null}
          {header?.kind === "video" ? (
            <div className="flex aspect-video w-full items-center justify-center bg-neutral-800 text-white/80">
              <Video className="size-6" aria-hidden />
            </div>
          ) : null}
          {header?.kind === "document" ? (
            <div className="flex items-center gap-2 bg-neutral-100 px-3 py-2 text-xs text-neutral-600">
              <FileText className="size-4 shrink-0" aria-hidden /> PDF
            </div>
          ) : null}
          <div className="space-y-1 px-2.5 pb-1.5 pt-2">
            {header?.kind === "text" ? <p className="font-semibold">{header.text}</p> : null}
            <p className="whitespace-pre-wrap break-words">{message.body}</p>
            {message.footer ? <p className="text-xs text-neutral-500">{message.footer}</p> : null}
            <p className="text-right text-[10px] text-neutral-400">10:30</p>
          </div>
          <button
            type="button"
            onClick={onOpenForm}
            className="flex w-full items-center justify-center gap-1.5 border-t border-neutral-200 py-2 text-[13px] font-medium text-[#027eb5] hover:bg-neutral-50"
          >
            <ListChecks className="size-4" aria-hidden />
            <span className="truncate">{message.cta}</span>
          </button>
        </div>
      </div>
    </>
  )
}
