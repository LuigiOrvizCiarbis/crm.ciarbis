"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, FileImage, FileText, Image as ImageIcon, Loader2, Plus, Type, Video, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { createTemplate, uploadTemplateHeader, type CreateTemplatePayload } from "@/lib/api/templates"
import { getFlows } from "@/lib/api/whatsapp-flows"
import type { WhatsAppFlow, WhatsAppFlowTextCompletion, WhatsAppTemplate } from "@/data/types"
import { DEFAULT_CLOSING_BODY } from "@/lib/flow-builder/completion"

type HeaderFormat = "NONE" | "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT"
type MediaFormat = Exclude<HeaderFormat, "NONE" | "TEXT">

const HEADER_OPTIONS: Array<{ value: HeaderFormat; key: string; Icon?: typeof ImageIcon }> = [
  { value: "NONE", key: "none" },
  { value: "TEXT", key: "text", Icon: Type },
  { value: "IMAGE", key: "image", Icon: ImageIcon },
  { value: "VIDEO", key: "video", Icon: Video },
  { value: "DOCUMENT", key: "document", Icon: FileText },
]

// Tipos y tamaños que Meta acepta como ejemplo del encabezado; el CRM sube
// el archivo como header_handle con un tope de 16 MB.
const HEADER_MEDIA: Record<MediaFormat, { accept: string; maxMb: number; key: string }> = {
  IMAGE: { accept: "image/jpeg,image/png", maxMb: 5, key: "image" },
  VIDEO: { accept: "video/mp4", maxMb: 16, key: "video" },
  DOCUMENT: { accept: "application/pdf", maxMb: 16, key: "document" },
}

const isMediaFormat = (format: HeaderFormat): format is MediaFormat => format in HEADER_MEDIA
type ButtonDraft = {
  type: "QUICK_REPLY" | "URL" | "PHONE_NUMBER" | "FLOW"
  text: string
  url?: string
  phone_number?: string
  flow_id?: string
  flow_action?: "navigate" | "data_exchange"
  navigate_screen?: string
}

/** Contenido inicial del diálogo; el que lo abre lo monta con una `key` nueva por borrador. */
export interface TemplateDraft {
  name: string
  headerText: string | null
  body: string
  footer: string | null
  /** Con formulario, la plantilla lleva su botón FLOW con `buttonText`. */
  flow?: WhatsAppFlow
  buttonText?: string
  /** Botón con link, cuando no hay formulario. */
  link?: { text: string; url: string } | null
}

/** Botones de plantilla: Meta admite hasta 25 caracteres (el mensaje interactivo, 30). */
const BUTTON_TEXT_MAX = 25

const templateNameFrom = (value: string) =>
  value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 60)

/** Plantilla que invita a completar el formulario, armada con su mensaje de envío. */
export function flowTemplateDraft(flow: WhatsAppFlow): TemplateDraft {
  const { header, body, footer, cta } = flow.send_defaults
  return {
    name: `${templateNameFrom(flow.name) || "formulario"}_invitacion`,
    headerText: header,
    body,
    footer,
    flow,
    buttonText: cta.slice(0, BUTTON_TEXT_MAX),
  }
}

/** Plantilla de cierre, sin botón de formulario: parte del mensaje de texto si hay uno. */
export function closingTemplateDraft(flow: WhatsAppFlow, text: WhatsAppFlowTextCompletion | null): TemplateDraft {
  return {
    name: `${templateNameFrom(flow.name) || "formulario"}_cierre`,
    headerText: null,
    body: text?.body ?? DEFAULT_CLOSING_BODY,
    footer: null,
    link: text?.button_text && text.url ? { text: text.button_text.slice(0, BUTTON_TEXT_MAX), url: text.url } : null,
  }
}

/** Meta acepta el botón de formulario si el formulario tiene una versión publicada vigente. */
export const canUseFlowInTemplate = (flow: WhatsAppFlow) =>
  flow.has_published_version && flow.status !== "DEPRECATED" && flow.status !== "BLOCKED"

// La acción sale del formulario. Los del constructor abren su primera
// pantalla sin consultar al CRM (la precarga llega con el envío). Los de JSON
// con endpoint abren consultando al CRM (precarga y turnos); sin endpoint,
// abren su primera pantalla. Abrir en otra pantalla rompería formularios que
// dependen de las anteriores.
function flowButtonTarget(flow: WhatsAppFlow | undefined, flowId: string): Partial<ButtonDraft> {
  return flow?.uses_endpoint && !flow.has_builder_definition
    ? { flow_id: flowId || undefined, flow_action: "data_exchange", navigate_screen: undefined }
    : { flow_id: flowId || undefined, flow_action: "navigate", navigate_screen: flow?.screens[0] }
}

interface NewTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
  onCreated: (template: WhatsAppTemplate) => Promise<void>
  draft?: TemplateDraft | null
}

export function NewTemplateDialog({ open, onOpenChange, channelId, onCreated, draft }: NewTemplateDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [name, setName] = useState(draft?.name ?? "")
  const [language, setLanguage] = useState("es_AR")
  const [category, setCategory] = useState<"MARKETING" | "UTILITY">("UTILITY")
  const [headerFormat, setHeaderFormat] = useState<HeaderFormat>(draft?.headerText ? "TEXT" : "NONE")
  const [headerText, setHeaderText] = useState(draft?.headerText ?? "")
  const [headerFile, setHeaderFile] = useState<File | null>(null)
  const [headerPreviewUrl, setHeaderPreviewUrl] = useState<string | null>(null)
  const [body, setBody] = useState(draft?.body ?? "")
  const [footer, setFooter] = useState(draft?.footer ?? "")
  const [examples, setExamples] = useState<Record<string, string>>({})
  const [buttons, setButtons] = useState<ButtonDraft[]>(() => draft?.flow
    ? [{ type: "FLOW", text: draft.buttonText ?? "", ...flowButtonTarget(draft.flow, draft.flow.meta_flow_id) }]
    : draft?.link
      ? [{ type: "URL", text: draft.link.text, url: draft.link.url }]
      : [])
  const [publishedFlows, setPublishedFlows] = useState<WhatsAppFlow[] | null>(null)
  const [flowsLoading, setFlowsLoading] = useState(false)
  const hasFlowButton = buttons.some((button) => button.type === "FLOW")
  const [submitting, setSubmitting] = useState(false)
  const [confirmDiscard, setConfirmDiscard] = useState(false)
  const variables = useMemo(() => [...new Set(Array.from(body.matchAll(/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/g)).map((match) => match[1]))], [body])
  const hasDraft = Boolean(name || body.trim() || footer.trim() || headerFile || headerText.trim() || buttons.length > 0)

  useEffect(() => setExamples((current) => Object.fromEntries(variables.map((variable) => [variable, current[variable] || ""]))), [variables])

  // El archivo elegido se muestra en la vista previa; su URL se libera al reemplazarlo.
  const replaceHeaderFile = (file: File | null, format: HeaderFormat) => {
    if (headerPreviewUrl) URL.revokeObjectURL(headerPreviewUrl)
    setHeaderPreviewUrl(file && format !== "DOCUMENT" ? URL.createObjectURL(file) : null)
    setHeaderFile(file)
  }

  const chooseHeaderFile = (file: File | null) => {
    if (file && isMediaFormat(headerFormat) && file.size > HEADER_MEDIA[headerFormat].maxMb * 1024 * 1024) {
      addToast({ type: "error", title: t("templateDialog.errors.fileTooLarge", { mb: HEADER_MEDIA[headerFormat].maxMb }) })
      return
    }
    replaceHeaderFile(file, headerFormat)
  }

  const changeHeaderFormat = (format: HeaderFormat) => {
    setHeaderFormat(format)
    replaceHeaderFile(null, format)
  }

  // Los formularios se cargan recién cuando hay un botón FLOW, y se descartan
  // al cambiar de canal porque pertenecen a la cuenta de WhatsApp del canal.
  useEffect(() => { setPublishedFlows(null) }, [channelId])
  useEffect(() => {
    if (!open || !hasFlowButton || publishedFlows !== null || flowsLoading) return
    setFlowsLoading(true)
    getFlows(channelId)
      .then((flows) => setPublishedFlows(flows.filter(canUseFlowInTemplate)))
      .catch((error: unknown) => {
        setPublishedFlows([])
        addToast({ type: "error", title: error instanceof Error ? error.message : t("templateDialog.errors.flows") })
      })
      .finally(() => setFlowsLoading(false))
  }, [open, hasFlowButton, publishedFlows, flowsLoading, channelId, addToast, t])

  const updateButton = (index: number, patch: Partial<ButtonDraft>) =>
    setButtons((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item))

  const changeButtonType = (index: number, type: ButtonDraft["type"]) =>
    setButtons((current) => current.map((item, itemIndex) => itemIndex === index
      ? type === "FLOW"
        ? { type, text: item.text, flow_action: "navigate" }
        : { type, text: item.text, ...(type === "URL" && item.url ? { url: item.url } : {}), ...(type === "PHONE_NUMBER" && item.phone_number ? { phone_number: item.phone_number } : {}) }
      : item))

  const selectFlow = (index: number, flowId: string) =>
    updateButton(index, flowButtonTarget(publishedFlows?.find((item) => item.meta_flow_id === flowId), flowId))

  const reset = () => {
    setName(""); setLanguage("es_AR"); setCategory("UTILITY")
    setHeaderText(""); changeHeaderFormat("NONE")
    setBody(""); setFooter(""); setExamples({}); setButtons([])
    setConfirmDiscard(false)
  }

  // Plantilla de utilidad para retomar conversaciones de WhatsApp fuera de la
  // ventana de 24 h. Meta decide la categoría final al revisarla. El texto es
  // contenido para el cliente en es_AR, no interfaz: no se traduce.
  const applyReengagementPreset = () => {
    setName("retomar_conversacion"); setLanguage("es_AR"); setCategory("UTILITY")
    setHeaderText(""); changeHeaderFormat("NONE")
    setBody("Hola {{nombre}}, te escribimos por la consulta que nos dejaste. Para continuar con la atención, respondé este mensaje."); setFooter("")
    setExamples({ nombre: "Juan" })
    setButtons([{ type: "QUICK_REPLY", text: "Continuar" }])
  }

  const requestClose = () => {
    // Cerrar a mitad del envío borraría el formulario sin saber si Meta lo recibió.
    if (submitting) return
    if (hasDraft) { setConfirmDiscard(true); return }
    reset()
    onOpenChange(false)
  }

  const submit = async () => {
    if (!name.match(/^[a-z0-9_]+$/)) return addToast({ type: "error", title: t("templateDialog.errors.name") })
    if (!body.trim()) return addToast({ type: "error", title: t("templateDialog.errors.body") })
    if (variables.some((variable) => !examples[variable]?.trim())) return addToast({ type: "error", title: t("templateDialog.errors.examples") })
    if (headerFormat !== "NONE" && headerFormat !== "TEXT" && !headerFile) return addToast({ type: "error", title: t("templateDialog.errors.headerFile") })
    if (buttons.some((button) => button.type === "FLOW" && !button.flow_id)) return addToast({ type: "error", title: t("templateDialog.errors.flowButton") })
    setSubmitting(true)
    try {
      let headerHandle: string | undefined
      if (headerFile) headerHandle = (await uploadTemplateHeader(channelId, headerFile)).header_handle
      const components: CreateTemplatePayload["components"] = []
      if (headerFormat === "TEXT") components.push({ type: "HEADER", format: "TEXT", text: headerText.trim() })
      if (headerHandle) components.push({ type: "HEADER", format: headerFormat, example: { header_handle: [headerHandle] } })
      components.push({ type: "BODY", text: body.trim(), ...(variables.length ? { example: { body_text_named_params: variables.map((param_name) => ({ param_name, example: examples[param_name].trim() })) } } : {}) })
      if (footer.trim()) components.push({ type: "FOOTER", text: footer.trim() })
      if (buttons.length) components.push({
        type: "BUTTONS",
        buttons: buttons.map(({ type, text, url, phone_number, flow_id, flow_action, navigate_screen }) => ({
          type,
          text,
          ...(url ? { url } : {}),
          ...(phone_number ? { phone_number } : {}),
          ...(type === "FLOW" ? { flow_id, flow_action: flow_action ?? "navigate", ...(flow_action !== "data_exchange" && navigate_screen ? { navigate_screen } : {}) } : {}),
        })),
      })
      const created = await createTemplate(channelId, { name: name.trim(), language, category, parameter_format: "named", components })
      addToast({ type: "success", title: t("templateDialog.created") })
      reset()
      onOpenChange(false)
      await onCreated(created)
    } catch (error: unknown) {
      addToast({ type: "error", title: (error instanceof Error && error.message) || t("templateDialog.errors.create") })
    } finally {
      setSubmitting(false)
    }
  }

  const optional = <span className="font-normal text-muted-foreground">{t("templateDialog.optional")}</span>

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) requestClose(); else onOpenChange(true) }}>
      <DialogContent className="flex max-h-[92vh] max-w-4xl flex-col overflow-hidden p-0 sm:max-w-4xl">
        {confirmDiscard ? (
          <div className="flex flex-col gap-4 p-6">
            <DialogHeader className="p-0">
              <DialogTitle>{t("templateDialog.discard.title")}</DialogTitle>
              <DialogDescription>{t("templateDialog.discard.description")}</DialogDescription>
            </DialogHeader>
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setConfirmDiscard(false)}>{t("templateDialog.discard.keep")}</Button>
              <Button variant="destructive" size="sm" onClick={() => { reset(); onOpenChange(false) }}>{t("templateDialog.discard.confirm")}</Button>
            </div>
          </div>
        ) : (
          <>
            <DialogHeader className="border-b border-border px-6 py-4">
              <DialogTitle>{t("templateDialog.title")}</DialogTitle>
              <DialogDescription>{t("templateDialog.description")}</DialogDescription>
              <div className="flex flex-col items-start gap-1.5 pt-2">
                <Button variant="outline" size="sm" type="button" onClick={applyReengagementPreset}>{t("templateDialog.reengagement")}</Button>
                <p className="text-xs text-muted-foreground">{t("templateDialog.reengagementHint")}</p>
              </div>
            </DialogHeader>

            <div className="grid flex-1 gap-6 overflow-y-auto px-6 py-6 lg:grid-cols-[1.3fr_0.7fr]">
              <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="block space-y-1.5 text-sm font-medium">
                    {t("templateDialog.name")}
                    <Input value={name} onChange={(event) => setName(event.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_"))} placeholder={t("templateDialog.namePlaceholder")} />
                  </label>
                  <label className="block space-y-1.5 text-sm font-medium">
                    {t("templateDialog.language")}
                    <select value={language} onChange={(event) => setLanguage(event.target.value)} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                      <option value="es_AR">Español (Argentina)</option>
                      <option value="es_MX">Español (México)</option>
                      <option value="en_US">English (US)</option>
                      <option value="pt_BR">Português (Brasil)</option>
                    </select>
                  </label>
                </div>

                <label className="block space-y-1.5 text-sm font-medium">
                  {t("templateDialog.category")}
                  <select value={category} onChange={(event) => setCategory(event.target.value as "MARKETING" | "UTILITY")} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                    <option value="UTILITY">{t("templateDialog.categories.utility")}</option>
                    <option value="MARKETING">{t("templateDialog.categories.marketing")}</option>
                  </select>
                </label>

                <div className="space-y-3 rounded-lg border border-border p-4">
                  <p className="text-sm font-medium">{t("templateDialog.header")} {optional}</p>
                  <div className="flex flex-wrap gap-1.5" role="group" aria-label={t("templateDialog.headerType")}>
                    {HEADER_OPTIONS.map(({ value, key, Icon }) => (
                      <Button
                        key={value}
                        type="button"
                        size="sm"
                        aria-pressed={headerFormat === value}
                        variant={headerFormat === value ? "default" : "outline"}
                        onClick={() => changeHeaderFormat(value)}
                      >
                        {Icon && <Icon className="mr-1 size-3.5" aria-hidden />} {t(`templateDialog.headerFormats.${key}`)}
                      </Button>
                    ))}
                  </div>
                  {headerFormat === "TEXT" && (
                    <Input
                      value={headerText}
                      onChange={(event) => setHeaderText(event.target.value)}
                      maxLength={60}
                      aria-label={t("templateDialog.headerFormats.text")}
                      placeholder={t("templateDialog.headerTextPlaceholder")}
                    />
                  )}
                  {isMediaFormat(headerFormat) && (
                    <div className="space-y-1.5">
                      <label className="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground hover:bg-muted/40 focus-within:ring-[3px] focus-within:ring-ring/50">
                        {headerFormat === "IMAGE" && headerPreviewUrl ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={headerPreviewUrl} alt="" className="size-12 shrink-0 rounded object-cover" />
                        ) : (
                          <FileImage className="size-4 shrink-0" aria-hidden />
                        )}
                        <span className="min-w-0 truncate">
                          {headerFile?.name || (headerFormat === "IMAGE" ? t("templateDialog.chooseImage") : t("templateDialog.chooseFile"))}
                        </span>
                        <input
                          key={headerFormat}
                          type="file"
                          className="sr-only"
                          accept={HEADER_MEDIA[headerFormat].accept}
                          onChange={(event) => chooseHeaderFile(event.target.files?.[0] || null)}
                        />
                      </label>
                      <p className="text-xs text-muted-foreground">
                        {t("templateDialog.mediaHint", { limits: t(`templateDialog.mediaLimits.${HEADER_MEDIA[headerFormat].key}`) })}
                      </p>
                    </div>
                  )}
                </div>

                <label className="block space-y-1.5 text-sm font-medium">
                  {t("templateDialog.body")}
                  <Textarea value={body} onChange={(event) => setBody(event.target.value)} rows={5} placeholder={t("templateDialog.bodyPlaceholder")} />
                  <span className="block text-xs font-normal text-muted-foreground">{t("templateDialog.bodyHint")}</span>
                </label>

                {variables.length > 0 && (
                  <div className="space-y-3 rounded-lg border border-border bg-muted/20 p-4">
                    <p className="text-sm font-medium">{t("templateDialog.examples")}</p>
                    {variables.map((variable) => (
                      <label key={variable} className="flex items-center gap-3 text-sm">
                        <code className="min-w-28 rounded bg-background px-2 py-1 text-xs">&#123;&#123;{variable}&#125;&#125;</code>
                        <Input value={examples[variable] || ""} onChange={(event) => setExamples((current) => ({ ...current, [variable]: event.target.value }))} placeholder={t("templateDialog.examplePlaceholder")} />
                      </label>
                    ))}
                  </div>
                )}

                <label className="block space-y-1.5 text-sm font-medium">
                  {t("templateDialog.footer")} {optional}
                  <Input value={footer} onChange={(event) => setFooter(event.target.value)} maxLength={60} placeholder={t("templateDialog.footerPlaceholder")} />
                </label>

                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium">{t("templateDialog.buttons")} <span className="font-normal text-muted-foreground">{t("templateDialog.buttonsHint")}</span></p>
                    {buttons.length < 3 && (
                      <Button type="button" size="sm" variant="outline" onClick={() => setButtons((current) => [...current, { type: "QUICK_REPLY", text: "" }])}>
                        <Plus className="mr-1 size-3" /> {t("templateDialog.addButton")}
                      </Button>
                    )}
                  </div>
                  {buttons.map((button, index) => {
                    const otherFlowButton = buttons.some((item, itemIndex) => itemIndex !== index && item.type === "FLOW")
                    return (
                    <div key={index} className="grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-[140px_1fr_auto]">
                      <select
                        value={button.type}
                        onChange={(event) => changeButtonType(index, event.target.value as ButtonDraft["type"])}
                        aria-label={t("templateDialog.buttonType")}
                        className="h-10 rounded-md border border-input bg-background px-2 text-sm"
                      >
                        <option value="QUICK_REPLY">{t("templateDialog.buttonTypes.quickReply")}</option>
                        <option value="URL">URL</option>
                        <option value="PHONE_NUMBER">{t("templateDialog.buttonTypes.phone")}</option>
                        <option value="FLOW" disabled={otherFlowButton}>{t("templateDialog.buttonTypes.flow")}</option>
                      </select>
                      <Input
                        value={button.text}
                        onChange={(event) => updateButton(index, { text: event.target.value })}
                        maxLength={BUTTON_TEXT_MAX}
                        aria-label={t("templateDialog.buttonText")}
                        placeholder={t("templateDialog.buttonText")}
                      />
                      {button.type === "URL" && (
                        <Input value={button.url || ""} onChange={(event) => updateButton(index, { url: event.target.value })} aria-label="URL" placeholder="https://…" />
                      )}
                      {button.type === "PHONE_NUMBER" && (
                        <Input value={button.phone_number || ""} onChange={(event) => updateButton(index, { phone_number: event.target.value })} aria-label={t("templateDialog.buttonTypes.phone")} placeholder="54911…" />
                      )}
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => setButtons((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                        aria-label={t("templateDialog.removeButton", { label: button.text || String(index + 1) })}
                      >
                        <X className="size-4" aria-hidden="true" />
                      </Button>
                      {button.type === "FLOW" && (
                        <div className="sm:col-span-3">
                          <label className="block space-y-1 text-xs font-medium text-muted-foreground">
                            {t("templateDialog.publishedFlow")}
                            <select
                              value={button.flow_id || ""}
                              disabled={flowsLoading}
                              onChange={(event) => selectFlow(index, event.target.value)}
                              className="h-10 w-full rounded-md border border-input bg-background px-2 text-sm text-foreground"
                            >
                              <option value="">
                                {flowsLoading
                                  ? t("templateDialog.flowsLoading")
                                  : publishedFlows?.length === 0
                                    ? t("templateDialog.noFlows")
                                    : t("templateDialog.chooseFlow")}
                              </option>
                              {(publishedFlows ?? []).map((flow) => <option key={flow.id} value={flow.meta_flow_id}>{flow.name}</option>)}
                            </select>
                          </label>
                        </div>
                      )}
                    </div>
                    )
                  })}
                </div>
              </div>

              <div className="rounded-lg border border-border bg-muted/30 p-4">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{t("templateDialog.preview")}</p>
                <div className="overflow-hidden rounded-lg rounded-tl-sm border border-border bg-card text-sm text-card-foreground shadow-xs">
                  {headerFormat === "IMAGE" && (headerPreviewUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={headerPreviewUrl} alt={t("templateDialog.headerImageAlt")} className="aspect-[1.91/1] w-full object-cover" />
                  ) : (
                    <div className="flex aspect-[1.91/1] w-full items-center justify-center bg-muted text-muted-foreground"><ImageIcon className="size-6" aria-hidden /></div>
                  ))}
                  {headerFormat === "VIDEO" && (headerPreviewUrl ? (
                    <video src={headerPreviewUrl} muted playsInline className="aspect-video w-full bg-black object-cover" />
                  ) : (
                    <div className="flex aspect-video w-full items-center justify-center bg-muted text-muted-foreground"><Video className="size-6" aria-hidden /></div>
                  ))}
                  {headerFormat === "DOCUMENT" && (
                    <div className="flex items-center gap-2 bg-muted px-3 py-2 text-xs text-muted-foreground">
                      <FileText className="size-4 shrink-0" aria-hidden /><span className="truncate">{headerFile?.name || "PDF"}</span>
                    </div>
                  )}
                  <div className="p-3">
                    {headerFormat === "TEXT" && (
                      <p className="mb-2 font-semibold">{headerText || t("templateDialog.header")}</p>
                    )}
                    <p className="whitespace-pre-wrap">{body || t("templateDialog.previewBody")}</p>
                    {footer && <p className="mt-2 text-xs text-muted-foreground">{footer}</p>}
                    {buttons.length > 0 && (
                      <div className="mt-3 space-y-1 border-t border-border pt-2">
                        {buttons.map((button, index) => (
                          <p key={index} className="text-center text-xs font-medium text-primary">{button.text || t("templateDialog.previewButton")}</p>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
                <p className="mt-4 text-xs leading-5 text-muted-foreground">{t("templateDialog.reviewNote")}</p>
              </div>
            </div>

            <DialogFooter className="border-t border-border px-6 py-4">
              <Button variant="outline" onClick={requestClose} disabled={submitting}>{t("templateDialog.cancel")}</Button>
              <Button onClick={submit} disabled={submitting}>
                {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Check className="mr-2 size-4" />} {t("templateDialog.submit")}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
