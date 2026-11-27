"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, FileImage, FileText, Image as ImageIcon, Loader2, Plus, Type, Video, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { createTemplate, updateTemplate, uploadTemplateHeader, type CreateTemplatePayload } from "@/lib/api/templates"
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
  type: "QUICK_REPLY" | "URL" | "PHONE_NUMBER" | "FLOW" | "CATALOG"
  text: string
  url?: string
  phone_number?: string
  flow_id?: string
  flow_action?: "navigate" | "data_exchange"
  navigate_screen?: string
}

/** Plantilla de cierre precargada; el que abre el diálogo lo monta con una `key` nueva por borrador. */
export interface TemplateDraft {
  name: string
  headerText: string | null
  body: string
  footer: string | null
  /** Botón con link del mensaje de cierre. */
  link?: { text: string; url: string } | null
}

/** Botones de plantilla: Meta admite hasta 25 caracteres (el mensaje interactivo, 30). */
export const BUTTON_TEXT_MAX = 25

export const TEMPLATE_LANGUAGES = [
  { value: "es_AR", label: "Español (Argentina)" },
  { value: "es_MX", label: "Español (México)" },
  { value: "en_US", label: "English (US)" },
  { value: "pt_BR", label: "Português (Brasil)" },
] as const

export const templateNameFrom = (value: string) =>
  value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 60)

/** Plantilla de cierre, sin botón de formulario: parte del mensaje de texto si hay uno. */
export function closingTemplateDraft(flow: WhatsAppFlow, text: WhatsAppFlowTextCompletion | null): TemplateDraft {
  return {
    name: `${templateNameFrom(flow.name) || "formulario"}_cierre`,
    headerText: text?.header ?? null,
    body: text?.body ?? DEFAULT_CLOSING_BODY,
    footer: text?.footer ?? null,
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
export function flowButtonTarget(flow: WhatsAppFlow | undefined, flowId: string): Partial<ButtonDraft> {
  return flow?.uses_endpoint && !flow.has_builder_definition
    ? { flow_id: flowId || undefined, flow_action: "data_exchange", navigate_screen: undefined }
    : { flow_id: flowId || undefined, flow_action: "navigate", navigate_screen: flow?.screens[0] }
}

const EDITABLE_STATUSES: WhatsAppTemplate["status"][] = ["APPROVED", "REJECTED", "PAUSED"]
const EDITABLE_BUTTONS = ["QUICK_REPLY", "URL", "PHONE_NUMBER", "FLOW", "CATALOG"]

/**
 * Por qué este editor no puede editar la plantilla, o null si puede. Meta sólo
 * deja editar aprobadas, rechazadas o pausadas. El editor arma variables con
 * nombre y sólo en el cuerpo: las plantillas con {{1}}, con variables en el
 * encabezado o en links, o de autenticación se editan en WhatsApp Manager.
 */
export function templateEditBlocker(template: WhatsAppTemplate): "status" | "unsupported" | null {
  if (!EDITABLE_STATUSES.includes(template.status)) return "status"
  if (template.category === "AUTHENTICATION") return "unsupported"
  const supported = template.components.every((component) => {
    switch (component.type) {
      case "BODY": return !/\{\{\s*\d+\s*\}\}/.test(component.text ?? "")
      case "HEADER":
      case "FOOTER": return !/\{\{/.test(component.text ?? "")
      case "BUTTONS": return (component.buttons ?? []).every((button) => EDITABLE_BUTTONS.includes(button.type) && !/\{\{/.test(button.url ?? ""))
      default: return false
    }
  })
  return supported ? null : "unsupported"
}

/** Campos del editor a partir de una plantilla guardada; el archivo del encabezado se vuelve a pedir. */
function fieldsFromTemplate(template: WhatsAppTemplate) {
  const find = (type: string) => template.components.find((component) => component.type === type)
  const header = find("HEADER")
  const body = find("BODY")
  const format = header?.format?.toUpperCase()
  const headerFormat: HeaderFormat = !header ? "NONE" : format === "IMAGE" || format === "VIDEO" || format === "DOCUMENT" ? format : "TEXT"
  return {
    headerFormat,
    headerText: headerFormat === "TEXT" ? header?.text ?? "" : "",
    body: body?.text ?? "",
    footer: find("FOOTER")?.text ?? "",
    examples: Object.fromEntries((body?.example?.body_text_named_params ?? []).map(({ param_name, example }) => [param_name, example])),
    buttons: (find("BUTTONS")?.buttons ?? []).flatMap((button): ButtonDraft[] =>
      button.type === "QUICK_REPLY" || button.type === "URL" || button.type === "PHONE_NUMBER" || button.type === "FLOW" || button.type === "CATALOG"
        ? [{ type: button.type, text: button.text, url: button.url, phone_number: button.phone_number, flow_id: button.flow_id, flow_action: button.flow_action, navigate_screen: button.navigate_screen }]
        : []),
  }
}

interface NewTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
  onCreated: (template: WhatsAppTemplate) => Promise<void>
  draft?: TemplateDraft | null
  /** Plantilla a editar; el que abre el diálogo lo monta con una `key` por plantilla. */
  template?: WhatsAppTemplate | null
}

export function NewTemplateDialog({ open, onOpenChange, channelId, onCreated, draft, template }: NewTemplateDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [edited] = useState(() => template ? fieldsFromTemplate(template) : null)
  const [name, setName] = useState(template?.name ?? draft?.name ?? "")
  const [language, setLanguage] = useState(template?.language ?? "es_AR")
  const [category, setCategory] = useState<"MARKETING" | "UTILITY">(template?.category === "MARKETING" ? "MARKETING" : "UTILITY")
  // Meta no deja cambiar la categoría de una plantilla aprobada.
  const categoryLocked = template?.status === "APPROVED"
  const [headerFormat, setHeaderFormat] = useState<HeaderFormat>(edited?.headerFormat ?? (draft?.headerText ? "TEXT" : "NONE"))
  const [headerText, setHeaderText] = useState(edited?.headerText ?? draft?.headerText ?? "")
  const [headerFile, setHeaderFile] = useState<File | null>(null)
  const [headerPreviewUrl, setHeaderPreviewUrl] = useState<string | null>(null)
  const [body, setBody] = useState(edited?.body ?? draft?.body ?? "")
  const [footer, setFooter] = useState(edited?.footer ?? draft?.footer ?? "")
  const [examples, setExamples] = useState<Record<string, string>>(edited?.examples ?? {})
  const [buttons, setButtons] = useState<ButtonDraft[]>(() => edited?.buttons ?? (draft?.link ? [{ type: "URL", text: draft.link.text, url: draft.link.url }] : []))
  const [publishedFlows, setPublishedFlows] = useState<WhatsAppFlow[] | null>(null)
  const [flowsLoading, setFlowsLoading] = useState(false)
  const hasFlowButton = buttons.some((button) => button.type === "FLOW")
  // Meta publica el catálogo como plantilla de Marketing y sin encabezado; un solo botón de catálogo.
  const hasCatalogButton = buttons.some((button) => button.type === "CATALOG")
  const [submitting, setSubmitting] = useState(false)
  const [confirmDiscard, setConfirmDiscard] = useState(false)
  const variables = useMemo(() => [...new Set(Array.from(body.matchAll(/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/g)).map((match) => match[1]))], [body])
  // Al editar, sólo hay algo que descartar si cambió respecto de lo guardado.
  const snapshot = JSON.stringify({ category, headerFormat, headerText, body, footer, examples, buttons, file: headerFile?.name ?? null })
  const [initialSnapshot] = useState(snapshot)
  const hasDraft = template
    ? snapshot !== initialSnapshot
    : Boolean(name || body.trim() || footer.trim() || headerFile || headerText.trim() || buttons.length > 0)
  // Una plantilla que parte de un mensaje ya escrito no vuelve a pedir sus textos: quedan plegados.
  // Si el texto trae variables, se abre para cargar sus ejemplos.
  const [editingTexts, setEditingTexts] = useState(() => Boolean(template) || !draft || /\{\{/.test(draft.body))

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

  const changeButtonType = (index: number, type: ButtonDraft["type"]) => {
    if (type === "CATALOG") {
      setButtons((current) => current.map((item, itemIndex) => itemIndex === index ? { type, text: item.text || t("templateDialog.catalogButtonText") } : item))
      setCategory("MARKETING")
      changeHeaderFormat("NONE")
      return
    }
    setButtons((current) => current.map((item, itemIndex) => itemIndex === index
      ? type === "FLOW"
        ? { type, text: item.text, flow_action: "navigate" }
        : { type, text: item.text, ...(type === "URL" && item.url ? { url: item.url } : {}), ...(type === "PHONE_NUMBER" && item.phone_number ? { phone_number: item.phone_number } : {}) }
      : item))
  }

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
    if (!body.trim()) { setEditingTexts(true); return addToast({ type: "error", title: t("templateDialog.errors.body") }) }
    if (variables.some((variable) => !examples[variable]?.trim())) { setEditingTexts(true); return addToast({ type: "error", title: t("templateDialog.errors.examples") }) }
    if (headerFormat !== "NONE" && headerFormat !== "TEXT" && !headerFile) { setEditingTexts(true); return addToast({ type: "error", title: t("templateDialog.errors.headerFile") }) }
    if (buttons.some((button) => button.type === "FLOW" && !button.flow_id)) { setEditingTexts(true); return addToast({ type: "error", title: t("templateDialog.errors.flowButton") }) }
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
      const saved = template
        ? await updateTemplate(channelId, template.id, { components, ...(categoryLocked ? {} : { category }) })
        : await createTemplate(channelId, { name: name.trim(), language, category, parameter_format: "named", components })
      addToast({ type: "success", title: t(template ? "templateDialog.edit.saved" : "templateDialog.created") })
      reset()
      onOpenChange(false)
      await onCreated(saved)
    } catch (error: unknown) {
      addToast({ type: "error", title: (error instanceof Error && error.message) || t(template ? "templateDialog.errors.edit" : "templateDialog.errors.create") })
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
              <DialogTitle>{template ? t("templateDialog.edit.title", { name: template.name }) : draft ? t("templateDialog.closing.title") : t("templateDialog.title")}</DialogTitle>
              <DialogDescription>{template ? t("templateDialog.edit.description") : draft ? t("templateDialog.closing.description") : t("templateDialog.description")}</DialogDescription>
              {template ? (
                categoryLocked && <p className="pt-1 text-xs text-muted-foreground">{t("templateDialog.edit.approvedHint")}</p>
              ) : draft ? (
                <p className="pt-1 text-xs text-muted-foreground">{t("templateDialog.closing.hint")}</p>
              ) : (
                <div className="flex flex-col items-start gap-1.5 pt-2">
                  <Button variant="outline" size="sm" type="button" onClick={applyReengagementPreset}>{t("templateDialog.reengagement")}</Button>
                  <p className="text-xs text-muted-foreground">{t("templateDialog.reengagementHint")}</p>
                </div>
              )}
            </DialogHeader>

            <div className="grid flex-1 gap-6 overflow-y-auto px-6 py-6 lg:grid-cols-[1.3fr_0.7fr]">
              <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="block space-y-1.5 text-sm font-medium">
                    {t("templateDialog.name")}
                    <Input value={name} disabled={Boolean(template)} onChange={(event) => setName(event.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_"))} placeholder={t("templateDialog.namePlaceholder")} />
                  </label>
                  <label className="block space-y-1.5 text-sm font-medium">
                    {t("templateDialog.language")}
                    <Select value={language} onValueChange={setLanguage} disabled={Boolean(template)}>
                      <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {TEMPLATE_LANGUAGES.map((item) => <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  </label>
                </div>

                {editingTexts ? (<>
                <label className="block space-y-1.5 text-sm font-medium">
                  {t("templateDialog.category")}
                  <Select value={category} onValueChange={(value) => setCategory(value as "MARKETING" | "UTILITY")} disabled={categoryLocked || hasCatalogButton}>
                    <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="UTILITY">{t("templateDialog.categories.utility")}</SelectItem>
                      <SelectItem value="MARKETING">{t("templateDialog.categories.marketing")}</SelectItem>
                    </SelectContent>
                  </Select>
                </label>

                {hasCatalogButton ? (
                  <p className="rounded-lg border border-border p-4 text-xs text-muted-foreground">{t("templateDialog.catalogHint")}</p>
                ) : (
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
                      {template && <p className="text-xs text-muted-foreground">{t("templateDialog.edit.mediaHint")}</p>}
                    </div>
                  )}
                </div>
                )}

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
                    const otherCatalogButton = buttons.some((item, itemIndex) => itemIndex !== index && item.type === "CATALOG")
                    return (
                    <div key={index} className="grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-[140px_1fr_auto]">
                      <Select value={button.type} onValueChange={(value) => changeButtonType(index, value as ButtonDraft["type"])}>
                        <SelectTrigger className="w-full" aria-label={t("templateDialog.buttonType")}><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="QUICK_REPLY">{t("templateDialog.buttonTypes.quickReply")}</SelectItem>
                          <SelectItem value="URL">URL</SelectItem>
                          <SelectItem value="PHONE_NUMBER">{t("templateDialog.buttonTypes.phone")}</SelectItem>
                          {/* Un cierre con botón de formulario abriría otro formulario con cada respuesta: el backend lo rechaza. */}
                          {draft ? null : (<>
                            <SelectItem value="FLOW" disabled={otherFlowButton}>{t("templateDialog.buttonTypes.flow")}</SelectItem>
                            <SelectItem value="CATALOG" disabled={otherCatalogButton}>{t("templateDialog.buttonTypes.catalog")}</SelectItem>
                          </>)}
                        </SelectContent>
                      </Select>
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
                      {button.type === "FLOW" ? (
                        <div className="sm:col-span-3">
                          <label className="block space-y-1 text-xs font-medium text-muted-foreground">
                            {t("templateDialog.publishedFlow")}
                            <Select
                              value={button.flow_id || undefined}
                              disabled={flowsLoading || publishedFlows?.length === 0}
                              onValueChange={(value) => selectFlow(index, value)}
                            >
                              <SelectTrigger className="w-full text-foreground">
                                <SelectValue
                                  placeholder={flowsLoading
                                    ? t("templateDialog.flowsLoading")
                                    : publishedFlows?.length === 0
                                      ? t("templateDialog.noFlows")
                                      : t("templateDialog.chooseFlow")}
                                />
                              </SelectTrigger>
                              <SelectContent>
                                {(publishedFlows ?? []).map((flow) => <SelectItem key={flow.id} value={flow.meta_flow_id}>{flow.name}</SelectItem>)}
                              </SelectContent>
                            </Select>
                          </label>
                        </div>
                      ) : null}
                    </div>
                    )
                  })}
                </div>
                </>) : (
                  <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4">
                    <p className="text-sm text-muted-foreground">{t("templateDialog.textsReady")}</p>
                    <Button type="button" size="sm" variant="outline" onClick={() => setEditingTexts(true)}>{t("templateDialog.editTexts")}</Button>
                  </div>
                )}
              </div>

              <div className="rounded-lg border border-border bg-muted/30 p-4">
                <p className="mb-3 text-sm font-semibold">{t("templateDialog.preview")}</p>
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
                {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Check className="mr-2 size-4" />} {t(template ? "templateDialog.edit.submit" : "templateDialog.submit")}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
