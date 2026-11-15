"use client"

import { useEffect, useRef, useState } from "react"
import { Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import type { TemplateParameter, WhatsAppFlow, WhatsAppFlowCompletionImage, WhatsAppFlowResponseField } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { ApiValidationError, updateFlow } from "@/lib/api/whatsapp-flows"
import { DEFAULT_CLOSING_BODY } from "@/lib/flow-builder/completion"
import { answerLabel, parameterIssue } from "@/lib/template-parameters"

import { ChatBubble } from "./journey/ChatBubble"
import { HeaderRow } from "./journey/TemplateParametersEditor"

/** Límites de Meta: cuerpo de un mensaje interactivo y texto del botón de link. */
const BODY_MAX = 1024
const BUTTON_MAX = 20

const PLACEHOLDER = /\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g

function isHttpsUrl(value: string): boolean {
  try {
    return new URL(value).protocol === "https:"
  } catch {
    return false
  }
}

// La imagen se edita con el mismo campo que el encabezado de una plantilla (archivo o URL).
const imageParameter = (image: WhatsAppFlowCompletionImage | null | undefined): TemplateParameter =>
  image?.source === "literal"
    ? { component: "header", source: "literal", value: image.value }
    : { component: "header", source: "media_asset", media_asset_id: image?.media_asset_id }

const completionImage = (parameter: TemplateParameter): WhatsAppFlowCompletionImage | null =>
  parameter.source === "literal"
    ? { source: "literal", value: parameter.value?.trim() ?? "" }
    : parameter.media_asset_id
      ? { source: "media_asset", media_asset_id: parameter.media_asset_id }
      : null

interface FlowCompletionMessageFormProps {
  flow: WhatsAppFlow
  /** Respuestas que se pueden citar: las de la versión publicada del formulario. */
  fields: WhatsAppFlowResponseField[]
  onSaved: (flow: WhatsAppFlow) => void
  /** Avisa si hay cambios sin guardar respecto del cierre de texto guardado. */
  onDirtyChange?: (dirty: boolean) => void
}

/** Cierre con un mensaje de texto: lo manda el CRM en el chat cuando llega la respuesta. */
export function FlowCompletionMessageForm({ flow, fields, onSaved, onDirtyChange }: FlowCompletionMessageFormProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const stored = flow.completion_message?.type === "text" ? flow.completion_message : null
  const bodyRef = useRef<HTMLTextAreaElement>(null)
  const [body, setBody] = useState(stored?.body ?? DEFAULT_CLOSING_BODY)
  const [withButton, setWithButton] = useState(Boolean(stored?.button_text))
  const [buttonText, setButtonText] = useState(stored?.button_text ?? "")
  const [url, setUrl] = useState(stored?.url ?? "")
  const [saving, setSaving] = useState(false)
  const [withImage, setWithImage] = useState(Boolean(stored?.image))
  const [image, setImage] = useState<TemplateParameter>(() => imageParameter(stored?.image))
  // Sólo un archivo subido en esta sesión tiene URL para la vista previa; uno guardado se ve como ícono.
  const [uploaded, setUploaded] = useState<{ id: number; url: string } | null>(null)
  const [imageErrors, setImageErrors] = useState<string[]>([])

  const labels = new Map(fields.map((field) => [field.key, answerLabel(field, t)]))
  const unknownKeys = [...new Set([...body.matchAll(PLACEHOLDER)].map((match) => match[1]))].filter(
    (key) => fields.length > 0 && !labels.has(key),
  )
  const urlInvalid = withButton && url.trim() !== "" && !isHttpsUrl(url.trim())
  const valid =
    body.trim() !== "" &&
    (!withButton || (buttonText.trim() !== "" && isHttpsUrl(url.trim()))) &&
    (!withImage || parameterIssue(image) === null)
  const dirty =
    body.trim() !== (stored?.body ?? DEFAULT_CLOSING_BODY) ||
    withButton !== Boolean(stored?.button_text) ||
    (withButton && (buttonText.trim() !== (stored?.button_text ?? "") || url.trim() !== (stored?.url ?? ""))) ||
    withImage !== Boolean(stored?.image) ||
    (withImage && JSON.stringify(completionImage(image)) !== JSON.stringify(stored?.image ?? null))
  const imagePreviewUrl = !withImage
    ? null
    : image.source === "literal"
      ? (isHttpsUrl(image.value?.trim() ?? "") ? (image.value?.trim() ?? null) : null)
      : uploaded && uploaded.id === image.media_asset_id
        ? uploaded.url
        : null

  useEffect(() => {
    if (!onDirtyChange) return
    onDirtyChange(dirty)
    return () => onDirtyChange(false)
  }, [dirty, onDirtyChange])

  const insertField = (key: string) => {
    const token = `{{${key}}}`
    const element = bodyRef.current
    const start = element?.selectionStart ?? body.length
    const end = element?.selectionEnd ?? body.length
    const next = (body.slice(0, start) + token + body.slice(end)).slice(0, BODY_MAX)
    setBody(next)
    requestAnimationFrame(() => {
      element?.focus()
      element?.setSelectionRange(start + token.length, start + token.length)
    })
  }

  const save = async () => {
    if (!valid || saving) return
    setSaving(true)
    try {
      const saved = await updateFlow(flow.id, {
        completion_message: {
          type: "text",
          body: body.trim(),
          button_text: withButton ? buttonText.trim() : null,
          url: withButton ? url.trim() : null,
          image: withImage ? completionImage(image) : null,
        },
      })
      setImageErrors([])
      onSaved(saved)
      addToast({ type: "success", title: t("flows.completion.saved") })
    } catch (error) {
      if (error instanceof ApiValidationError) {
        setImageErrors(Object.entries(error.errors).filter(([key]) => key.startsWith("completion_message.image")).flatMap(([, messages]) => messages))
      }
      addToast({
        type: "error",
        title: t("flows.completion.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setSaving(false)
    }
  }

  const preview = body.replace(PLACEHOLDER, (_, key: string) => `[${labels.get(key) ?? key}]`)

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted-foreground">{t("flows.completion.description")}</p>

      <div className="space-y-1.5">
        <Label htmlFor={`completion-${flow.id}-body`}>{t("flows.completion.body")}</Label>
        <Textarea
          id={`completion-${flow.id}-body`}
          ref={bodyRef}
          value={body}
          maxLength={BODY_MAX}
          rows={4}
          onChange={(event) => setBody(event.target.value)}
          aria-invalid={body.trim() === ""}
        />
        <div className="flex items-start justify-between gap-2 text-xs text-muted-foreground">
          <span>{fields.length > 0 ? t("flows.completion.variablesHint") : t("flows.completion.variablesHintJson")}</span>
          <span className="shrink-0">{body.length}/{BODY_MAX}</span>
        </div>
        {fields.length > 0 ? (
          <div className="flex flex-wrap gap-1.5" role="group" aria-label={t("flows.completion.insert")}>
            {fields.map((field) => (
              <Button key={field.key} type="button" size="sm" variant="outline" className="h-7 px-2 text-xs" onClick={() => insertField(field.key)}>
                {answerLabel(field, t)}
              </Button>
            ))}
          </div>
        ) : null}
        {unknownKeys.length > 0 ? (
          <p className="text-xs text-amber-600 dark:text-amber-400">
            {t("flows.completion.unknownKeys", { keys: unknownKeys.map((key) => `{{${key}}}`).join(", ") })}
          </p>
        ) : null}
      </div>

      <div className="space-y-3 rounded-lg border border-border p-3">
        <div className="flex items-center justify-between gap-3">
          <Label htmlFor={`completion-${flow.id}-image`} className="text-sm font-medium">
            {t("flows.completion.withImage")}
          </Label>
          <Switch id={`completion-${flow.id}-image`} checked={withImage} onCheckedChange={setWithImage} />
        </div>
        {withImage ? (
          <HeaderRow
            parameter={image}
            format="IMAGE"
            id={`completion-${flow.id}-image-source`}
            label={t("flows.completion.imageLimits")}
            disabled={saving}
            errors={imageErrors}
            onChange={(next) => {
              setImage(next)
              setImageErrors([])
            }}
            onUploaded={(asset) => setUploaded({ id: asset.id, url: asset.url })}
          />
        ) : null}
      </div>

      <div className="space-y-3 rounded-lg border border-border p-3">
        <div className="flex items-center justify-between gap-3">
          <Label htmlFor={`completion-${flow.id}-button`} className="text-sm font-medium">
            {t("flows.completion.withButton")}
          </Label>
          <Switch id={`completion-${flow.id}-button`} checked={withButton} onCheckedChange={setWithButton} />
        </div>
        {withButton ? (
          <>
            <div className="space-y-1.5">
              <Label htmlFor={`completion-${flow.id}-button-text`}>{t("flows.completion.buttonText")}</Label>
              <Input
                id={`completion-${flow.id}-button-text`}
                value={buttonText}
                maxLength={BUTTON_MAX}
                placeholder={t("flows.completion.buttonPlaceholder")}
                onChange={(event) => setButtonText(event.target.value)}
              />
              <p className="text-right text-xs text-muted-foreground">{buttonText.length}/{BUTTON_MAX}</p>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={`completion-${flow.id}-url`}>{t("flows.completion.url")}</Label>
              <Input
                id={`completion-${flow.id}-url`}
                type="url"
                inputMode="url"
                value={url}
                placeholder="https://"
                aria-invalid={urlInvalid}
                onChange={(event) => setUrl(event.target.value)}
              />
              {urlInvalid ? <p className="text-xs text-destructive">{t("flows.completion.urlInvalid")}</p> : null}
            </div>
          </>
        ) : null}
      </div>

      <div className="space-y-1.5">
        <p className="text-xs font-medium text-muted-foreground">{t("flows.completion.preview")}</p>
        <div className="rounded-lg bg-[#efeae2] p-3 dark:bg-muted/40">
          <ChatBubble
            content={{
              header: withImage ? { kind: "image", url: imagePreviewUrl } : null,
              body: preview,
              footer: null,
              buttons: withButton && buttonText.trim() ? [{ text: buttonText, kind: "link" }] : [],
            }}
          />
        </div>
      </div>

      <div className="flex justify-end">
        <Button type="button" size="sm" onClick={() => void save()} disabled={!valid || saving}>
          {saving && <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />}
          {t("common.save")}
        </Button>
      </div>
    </div>
  )
}
