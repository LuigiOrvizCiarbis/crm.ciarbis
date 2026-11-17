"use client"

import { useMemo } from "react"

import { ChatBubble, templateBubble } from "@/components/flows/journey/ChatBubble"
import { useManagedTemplates } from "@/components/flows/journey/useManagedTemplates"
import type { WhatsAppFlow, WhatsAppFlowResponseField } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { bodyParameterRows, bodyPreview, templateBodyPreview, templateParameterRows, textVariableNames } from "@/lib/template-parameters"

import { ChatScreen } from "./ChatScreen"

interface FlowClosingPreviewProps {
  flow: WhatsAppFlow | null
  channelId: number | null
  fields: WhatsAppFlowResponseField[]
  contactFields: ContactField[]
}

/** Lo que llega al chat cuando el cliente termina: el mensaje o la plantilla de cierre. */
export function FlowClosingPreview({ flow, channelId, fields, contactFields }: FlowClosingPreviewProps) {
  const { t } = useTranslation()
  const closing = flow?.completion_message ?? null
  const { templates, loading, failed, reload } = useManagedTemplates(closing?.type === "template" ? channelId : null)
  const context = useMemo(() => ({ answers: fields, contactFields }), [fields, contactFields])

  if (!flow || closing === null) {
    return (
      <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-xs text-muted-foreground">
        {flow ? t("flows.builder.preview.closingNone") : t("flows.builder.preview.closingSaveFirst")}
      </p>
    )
  }

  if (closing.type === "text") {
    const rows = bodyParameterRows(textVariableNames(closing.body), closing.variables, context)
    return (
      <ChatScreen>
        <ChatBubble
          content={{
            // Un archivo guardado se ve como ícono: la vista previa sólo conoce la URL de los links.
            header: closing.image ? { kind: "image", url: closing.image.source === "literal" ? closing.image.value : null } : null,
            body: bodyPreview(closing.body, rows, context, t),
            footer: null,
            buttons: closing.button_text ? [{ text: closing.button_text, kind: "link" }] : [],
          }}
        />
      </ChatScreen>
    )
  }

  const template = templates?.find((item) => item.id === closing.template_id)
  if (!template) {
    return (
      <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-xs text-muted-foreground">
        {loading ? (
          t("flows.journey.loadingTemplates")
        ) : failed ? (
          <>
            {t("flows.journey.templatesLoadError")}{" "}
            <button type="button" className="underline underline-offset-2" onClick={reload}>
              {t("flows.journey.retry")}
            </button>
          </>
        ) : (
          t("flows.journey.closing.missingTemplate", { id: closing.template_id })
        )}
      </p>
    )
  }

  const rows = templateParameterRows(template, closing.parameters, context)
  return (
    <ChatScreen>
      <ChatBubble content={templateBubble(template, templateBodyPreview(template, rows, context, t))} />
    </ChatScreen>
  )
}
