"use client"

import { useMemo, useState } from "react"

import { ChatBubble, templateBubble, type BubbleContent } from "@/components/flows/journey/ChatBubble"
import { templateStatusKey } from "@/components/flows/journey/TemplateStatusBadge"
import { useManagedTemplates } from "@/components/flows/journey/useManagedTemplates"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import type { WhatsAppFlow } from "@/data/types"
import { useImagePreviewUrl } from "@/hooks/useImagePreviewUrl"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { isRepeaterScreen } from "@/lib/flow-builder/repeater"
import type { FlowDefinition, TextBlock } from "@/lib/flow-builder/types"
import { bodyPreview, templateBodyPreview, templateParameterRows, type ParameterContext } from "@/lib/template-parameters"

import { ChatScreen } from "./ChatScreen"

/** Lo que la vista previa necesita para mostrar la apertura y el cierre del formulario. */
export interface FlowMessageContext {
  /** null mientras el formulario no se guardó. */
  flow: WhatsAppFlow | null
  channelId: number | null
}

// Espejo de WhatsAppFlow::sendDefaults() para un formulario sin guardar.
const DEFAULT_SEND_BODY = "Tocá el botón para completar el formulario."
const DEFAULT_SEND_CTA = "Completar"

type Translate = (key: string, params?: Record<string, string | number>) => string

function sendMessage(
  flow: WhatsAppFlow | null,
  definition: FlowDefinition,
  context: ParameterContext,
  imageUrl: string | null,
  t: Translate,
): BubbleContent {
  if (flow) {
    const { header, image, body, footer, cta, variables } = flow.send_defaults
    const text = bodyPreview(body, variables, context, t)
    const top: BubbleContent["header"] = image ? { kind: "image", url: imageUrl } : header ? { kind: "text", text: header } : null
    return { header: top, body: text, footer, buttons: [{ text: cta, kind: "flow" }] }
  }
  const entry = definition.screens[0]
  const heading = entry && !isRepeaterScreen(entry)
    ? entry.blocks.find((block): block is TextBlock => block.type === "heading" && block.text.trim() !== "")
    : undefined
  return { header: null, body: heading?.text ?? DEFAULT_SEND_BODY, footer: null, buttons: [{ text: DEFAULT_SEND_CTA, kind: "flow" }] }
}

interface FlowMessagePreviewProps extends FlowMessageContext {
  definition: FlowDefinition
  contactFields: ContactField[]
  onOpenForm: () => void
}

/** Mensaje de WhatsApp que abre el formulario: el de envío o una de sus plantillas. Se edita en Apertura. */
export function FlowMessagePreview({ flow, channelId, definition, contactFields, onOpenForm }: FlowMessagePreviewProps) {
  const { t } = useTranslation()
  const [source, setSource] = useState<"send" | number>("send")
  const { templates, loading, failed, reload } = useManagedTemplates(flow && flow.send_templates.length > 0 ? channelId : null)

  const linked = flow?.send_templates ?? []
  const selected = source === "send" ? undefined : linked.find((item) => item.id === source)
  const full = selected ? templates?.find((template) => template.id === selected.id) : undefined
  const context = useMemo(() => ({ answers: [], contactFields }), [contactFields])
  const imageUrl = useImagePreviewUrl(flow?.send_defaults.image)

  // Una plantilla elegida que no se puede mostrar no cae en el mensaje de envío: se avisa en su lugar.
  let message: BubbleContent | null = sendMessage(flow, definition, context, imageUrl, t)
  let notice: string | null = null
  if (flow && selected) {
    if (full) {
      const rows = templateParameterRows(full, flow.opening.templates[String(full.id)]?.parameters ?? [], context)
      message = templateBubble(full, templateBodyPreview(full, rows, context, t))
    } else {
      message = null
      notice = loading
        ? t("flows.journey.loadingTemplates")
        : channelId === null || failed
          ? t("flows.builder.message.templateUnavailable", { name: selected.name })
          : t("flows.builder.message.templateMissing", { name: selected.name })
    }
  }

  return (
    <div className="space-y-3">
      <ChatScreen>
        {message ? (
          <ChatBubble content={message} onButtonClick={(button) => button.kind === "flow" && onOpenForm()} />
        ) : (
          <p className="mx-auto mt-6 max-w-[85%] rounded-md bg-background/90 px-3 py-2 text-center text-xs text-muted-foreground">
            {notice}
          </p>
        )}
      </ChatScreen>

      <div className="space-y-2 rounded-lg border border-border p-3">
        <p className="text-xs font-medium text-foreground">{t("flows.builder.message.source")}</p>
        {flow ? (
          <Select value={selected ? String(selected.id) : "send"} onValueChange={(value) => setSource(value === "send" ? "send" : Number(value))}>
            <SelectTrigger className="w-full" aria-label={t("flows.builder.message.source")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="send">{t("flows.builder.message.sendSource")}</SelectItem>
              {linked.map((item) => (
                <SelectItem key={item.id} value={String(item.id)}>
                  {t("flows.builder.message.templateSource", {
                    name: item.name,
                    status: t(`flows.journey.templateStatus.${templateStatusKey(item.status)}`),
                  })}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : null}
        <p className="text-xs text-muted-foreground">
          {selected ? t("flows.builder.message.templateHint") : t("flows.builder.message.sendHint")}
        </p>
        {flow && channelId === null ? <p className="text-xs text-muted-foreground">{t("flows.journey.noChannel")}</p> : null}
        {selected && failed ? (
          <p className="text-xs text-destructive">
            {t("flows.journey.templatesLoadError")}{" "}
            <button type="button" className="underline underline-offset-2" onClick={reload}>
              {t("flows.journey.retry")}
            </button>
          </p>
        ) : null}
        <p className="text-xs text-muted-foreground">{flow ? t("flows.builder.message.editInOpening") : t("flows.builder.message.saveFirst")}</p>
      </div>
    </div>
  )
}
