"use client"

import { useState, type ChangeEvent } from "react"

import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import type { TemplateParameter, WhatsAppFlowSendDefaults } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import {
  NO_PARAMETER_ERRORS,
  bodyParameterRows,
  bodyPreview,
  cleanParameters,
  imageFromParameter,
  imageParameter,
  imagePreviewUrl,
  parameterIssue,
  type ParameterContext,
  type ParameterErrors,
} from "@/lib/template-parameters"

import { ChatBubble } from "./ChatBubble"
import { invalidVariables, messageVariableNames } from "./openingTemplate"
import { HeaderRow, TemplateParametersEditor } from "./TemplateParametersEditor"

/** Mismos límites que Meta para el mensaje interactivo de flow. */
const BODY_MAX = 1024
const CTA_MAX = 30
const HEADER_FOOTER_MAX = 60

/** Textos tal como se escriben (los opcionales vacíos son ""), la imagen y las variables tocadas. */
export interface SendMessageValues {
  header: string
  /** Con imagen no va el encabezado de texto: Meta admite uno solo. */
  withImage: boolean
  image: TemplateParameter
  body: string
  footer: string
  cta: string
  variables: TemplateParameter[]
}

export const sendValuesFrom = (message: WhatsAppFlowSendDefaults): SendMessageValues => ({
  header: message.header ?? "",
  withImage: message.image !== null,
  image: imageParameter(message.image),
  body: message.body,
  footer: message.footer ?? "",
  cta: message.cta,
  variables: message.variables,
})

/** Una fila por variable del cuerpo: la configurada o una sugerida (p. ej. {{nombre}} → Nombre del contacto). */
export const variableRows = (values: SendMessageValues, context: ParameterContext): TemplateParameter[] =>
  bodyParameterRows(messageVariableNames(values.body), values.variables, context)

/** La imagen está activada pero falta el archivo o la URL. */
export const imageIncomplete = (values: SendMessageValues): boolean => values.withImage && parameterIssue(values.image) !== null

export const toSendDefaults = (values: SendMessageValues, context: ParameterContext): WhatsAppFlowSendDefaults => ({
  header: values.withImage ? null : values.header.trim() || null,
  image: values.withImage ? imageFromParameter(values.image) : null,
  body: values.body.trim(),
  footer: values.footer.trim() || null,
  cta: values.cta.trim(),
  variables: cleanParameters(variableRows(values, context)),
})

interface FlowSendMessageFieldsProps {
  idPrefix: string
  values: SendMessageValues
  onChange: (values: SendMessageValues) => void
  context: ParameterContext
  /** Errores 422 de `send_defaults.variables`. */
  variableErrors?: ParameterErrors
  /** Errores 422 de `send_defaults.image`. */
  imageErrors?: string[]
  disabled?: boolean
}

/** Textos del mensaje que abre el formulario, sus variables y la vista previa en vivo. */
export function FlowSendMessageFields({
  idPrefix,
  values,
  onChange,
  context,
  variableErrors = NO_PARAMETER_ERRORS,
  imageErrors = [],
  disabled,
}: FlowSendMessageFieldsProps) {
  const { t } = useTranslation()
  const change = (key: "header" | "body" | "footer" | "cta") => (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    onChange({ ...values, [key]: event.target.value })
  const message = toSendDefaults(values, context)
  const rows = variableRows(values, context)
  const invalid = invalidVariables(values.body)
  const outsideBody = [values.withImage ? "" : values.header, values.footer, values.cta].some((text) => text.includes("{{"))
  // Sólo un archivo subido en esta pantalla tiene URL para la vista previa; uno guardado se ve como ícono.
  const [uploaded, setUploaded] = useState<{ id: number; url: string } | null>(null)

  return (
    <div className="space-y-3">
      <div className="space-y-3 rounded-lg border border-border p-3">
        <div className="flex items-center justify-between gap-3">
          <Label htmlFor={`${idPrefix}-image`} className="text-sm font-medium">
            {t("flows.completion.withImage")}
          </Label>
          <Switch
            id={`${idPrefix}-image`}
            checked={values.withImage}
            disabled={disabled}
            onCheckedChange={(withImage) => onChange({ ...values, withImage })}
          />
        </div>
        {values.withImage ? (
          <HeaderRow
            parameter={values.image}
            format="IMAGE"
            id={`${idPrefix}-image-source`}
            label={t("flows.journey.opening.imageHint")}
            disabled={Boolean(disabled)}
            errors={imageErrors}
            onChange={(image) => onChange({ ...values, image })}
            onUploaded={(asset) => setUploaded({ id: asset.id, url: asset.url })}
          />
        ) : (
          <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-header`}>{t("chats.flows.header")}</Label>
            <Input id={`${idPrefix}-header`} value={values.header} maxLength={HEADER_FOOTER_MAX} disabled={disabled} onChange={change("header")} />
          </div>
        )}
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`${idPrefix}-body`}>{t("chats.flows.body")}</Label>
        <Textarea
          id={`${idPrefix}-body`}
          value={values.body}
          maxLength={BODY_MAX}
          rows={3}
          disabled={disabled}
          aria-invalid={message.body === "" || invalid.length > 0}
          aria-describedby={`${idPrefix}-body-hint`}
          onChange={change("body")}
        />
        <div className="flex items-start justify-between gap-3 text-xs">
          <p id={`${idPrefix}-body-hint`} className={invalid.length > 0 ? "text-destructive" : "text-muted-foreground"}>
            {invalid.length > 0
              ? t("flows.journey.opening.variablesInvalid", { list: invalid.join(", ") })
              : t("flows.journey.opening.variablesHint")}
          </p>
          <span className="shrink-0 tabular-nums text-muted-foreground">{values.body.length}/{BODY_MAX}</span>
        </div>
      </div>
      {rows.length > 0 ? (
        <div className="space-y-2 rounded-md border border-border p-3">
          <p className="text-xs font-medium text-foreground">{t("flows.journey.opening.variablesTitle")}</p>
          <TemplateParametersEditor
            parameters={rows}
            onChange={(variables) => onChange({ ...values, variables })}
            context={context}
            errors={variableErrors}
            idPrefix={`${idPrefix}-variable`}
            disabled={disabled}
          />
        </div>
      ) : null}
      <div className="space-y-1.5">
        <Label htmlFor={`${idPrefix}-footer`}>{t("chats.flows.footer")}</Label>
        <Input id={`${idPrefix}-footer`} value={values.footer} maxLength={HEADER_FOOTER_MAX} disabled={disabled} onChange={change("footer")} />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`${idPrefix}-cta`}>{t("chats.flows.cta")}</Label>
        <Input
          id={`${idPrefix}-cta`}
          value={values.cta}
          maxLength={CTA_MAX}
          disabled={disabled}
          aria-invalid={message.cta === ""}
          onChange={change("cta")}
        />
        <p className="text-right text-xs text-muted-foreground">{values.cta.length}/{CTA_MAX}</p>
      </div>
      {outsideBody ? <p className="text-xs text-destructive">{t("flows.journey.opening.variablesOutsideBody")}</p> : null}
      <div className="space-y-1.5">
        <p className="text-xs font-medium text-muted-foreground">{t("flows.completion.preview")}</p>
        <div className="rounded-lg bg-[#efeae2] p-3 dark:bg-muted/40">
          <ChatBubble
            content={{
              header: values.withImage
                ? { kind: "image", url: imagePreviewUrl(values.image, uploaded) }
                : message.header
                  ? { kind: "text", text: message.header }
                  : null,
              body: bodyPreview(message.body, rows, context, t),
              footer: message.footer,
              buttons: message.cta ? [{ text: message.cta, kind: "flow" }] : [],
            }}
          />
        </div>
      </div>
    </div>
  )
}
