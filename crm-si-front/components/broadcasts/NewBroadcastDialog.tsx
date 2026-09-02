"use client"

import { useEffect, useMemo, useState } from "react"
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CalendarDays,
  Check,
  Clock3,
  FileUp,
  Info,
  Loader2,
  MessageCircle,
  Plus,
  Send,
  Sparkles,
  Trash2,
  Users,
} from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Channel, WhatsAppTemplate } from "@/data/types"
import { PipelineStage } from "@/lib/api/pipeline"
import { Tag } from "@/lib/api/tags"
import { ContactField } from "@/lib/api/contact-fields"
import { BroadcastConfirmationRequiredError, BroadcastEstimate, BroadcastFilter, BroadcastPayload, createBroadcast, estimateBroadcast } from "@/lib/api/broadcasts"
import { uploadTemplateMedia } from "@/lib/api/templates"
import {
  buildSendComponents,
  buildTemplatePreview,
  defaultParamSource,
  extractBodyParams,
  getHeaderMediaFormat,
  hasUnsupportedParams,
  resolveParamValue,
  ParamSource,
} from "@/lib/whatsapp-templates"
import { useToast } from "@/components/Toast"
import { cn } from "@/lib/utils"

type FieldOption = Pick<ContactField, "key" | "label" | "type">

interface NewBroadcastDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channel: Channel
  templates: WhatsAppTemplate[]
  initialTemplateId: number | null
  stages: PipelineStage[]
  tags: Tag[]
  fields: FieldOption[]
  onCreated: () => Promise<void>
}

const steps = ["Plantilla", "Audiencia", "Lanzamiento"]
const intervals: Array<{ value: BroadcastPayload["interval_seconds"]; label: string }> = [
  { value: 0, label: "Todos juntos" },
  { value: 15, label: "Cada 15 segundos" },
  { value: 30, label: "Cada 30 segundos" },
  { value: 60, label: "Cada 1 minuto" },
  { value: 120, label: "Cada 2 minutos" },
]

const currency = new Intl.NumberFormat("es-AR", { style: "currency", currency: "USD", minimumFractionDigits: 2 })

export function NewBroadcastDialog({ open, onOpenChange, channel, templates, initialTemplateId, stages, tags, fields, onCreated }: NewBroadcastDialogProps) {
  const { addToast } = useToast()
  const approvedTemplates = useMemo(() => templates.filter((template) => template.status === "APPROVED" && !hasUnsupportedParams(template.components)), [templates])
  const [step, setStep] = useState(0)
  const [templateId, setTemplateId] = useState<number | null>(null)
  const [name, setName] = useState("")
  const [paramSources, setParamSources] = useState<ParamSource[]>([])
  const [paramValues, setParamValues] = useState<string[]>([])
  const [mediaFile, setMediaFile] = useState<File | null>(null)
  const [mediaUrl, setMediaUrl] = useState("")
  const [stageId, setStageId] = useState<string>("all")
  const [tagIds, setTagIds] = useState<number[]>([])
  const [excludedTagIds, setExcludedTagIds] = useState<number[]>([])
  const [customFilters, setCustomFilters] = useState<BroadcastFilter[]>([])
  const [launch, setLaunch] = useState<"now" | "scheduled">("now")
  const [scheduledAt, setScheduledAt] = useState("")
  const [intervalSeconds, setIntervalSeconds] = useState<BroadcastPayload["interval_seconds"]>(0)
  const [estimate, setEstimate] = useState<BroadcastEstimate | null>(null)
  const [estimating, setEstimating] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [includeWithoutConsent, setIncludeWithoutConsent] = useState(false)
  const [acknowledgeConsentRisk, setAcknowledgeConsentRisk] = useState(false)
  const [acknowledgeAudienceSize, setAcknowledgeAudienceSize] = useState(false)
  const [acknowledgeMessagingLimit, setAcknowledgeMessagingLimit] = useState(false)
  const [consentRisks, setConsentRisks] = useState<string[] | null>(null)
  const [audienceSizeConfirmationRequired, setAudienceSizeConfirmationRequired] = useState(false)

  const selectedTemplate = approvedTemplates.find((template) => template.id === templateId) ?? null
  const allTagIds = useMemo(() => tags.map((tag) => tag.id), [tags])
  const allIncludedTagsSelected = tags.length > 0 && tagIds.length === tags.length
  const allExcludedTagsSelected = tags.length > 0 && excludedTagIds.length === tags.length
  const bodyParams = useMemo(() => selectedTemplate ? extractBodyParams(selectedTemplate.components) : { names: [], named: false }, [selectedTemplate])
  const mediaFormat = selectedTemplate ? getHeaderMediaFormat(selectedTemplate.components) : null

  useEffect(() => {
    if (!open) return
    const selectedId = approvedTemplates.some((template) => template.id === initialTemplateId)
      ? initialTemplateId
      : approvedTemplates[0]?.id ?? null
    setStep(0)
    setTemplateId(selectedId)
    setName("")
    setStageId("all")
    setTagIds([])
    setExcludedTagIds([])
    setCustomFilters([])
    setLaunch("now")
    setScheduledAt("")
    setIntervalSeconds(0)
    setEstimate(null)
    setMediaFile(null)
    setMediaUrl("")
    setIncludeWithoutConsent(false)
    setAcknowledgeConsentRisk(false)
    setAcknowledgeAudienceSize(false)
    setAcknowledgeMessagingLimit(false)
    setConsentRisks(null)
  }, [approvedTemplates, initialTemplateId, open])

  useEffect(() => {
    const names = selectedTemplate ? extractBodyParams(selectedTemplate.components).names : []
    setParamSources(names.map(defaultParamSource))
    setParamValues(names.map(() => ""))
    setMediaFile(null)
    setMediaUrl("")
  }, [selectedTemplate])

  const filters = useMemo(() => ({
    ...(stageId !== "all" ? { pipeline_stage_id: Number(stageId) } : {}),
    ...(tagIds.length ? { tag_ids: tagIds } : {}),
    ...(excludedTagIds.length ? { excluded_tag_ids: excludedTagIds } : {}),
    ...(customFilters.length ? { custom_filters: customFilters } : {}),
  }), [customFilters, excludedTagIds, stageId, tagIds])

  const basePayload = (): BroadcastPayload => ({
    name: name.trim() || "Nueva difusión",
    channel_id: channel.id,
    template_id: templateId as number,
    components: [],
    filters,
    launch,
    ...(launch === "scheduled" && scheduledAt ? { scheduled_at: new Date(scheduledAt).toISOString() } : {}),
    interval_seconds: intervalSeconds,
    include_without_consent: includeWithoutConsent,
    acknowledge_consent_risk: acknowledgeConsentRisk,
    acknowledge_audience_size: acknowledgeAudienceSize,
    acknowledge_messaging_limit: acknowledgeMessagingLimit,
  })

  const templateReady = selectedTemplate
    && paramValues.every((value, index) => paramSources[index] !== "custom" || value.trim().length > 0)
    && (!mediaFormat || mediaFile !== null || mediaUrl.trim().length > 0)
  const audienceReady = name.trim().length > 0 && customFilters.every((filter) => filter.value.trim().length > 0)
  const launchReady = launch === "now" || Boolean(scheduledAt && new Date(scheduledAt) > new Date())

  const goForward = async () => {
    if (step === 0 && templateReady) {
      setStep(1)
      return
    }
    if (step === 1 && audienceReady) {
      setEstimating(true)
      try {
        const result = await estimateBroadcast({ ...basePayload(), launch: "now", scheduled_at: undefined })
        setEstimate(result)
        setStep(2)
      } catch (error) {
        addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo calcular la audiencia" })
      } finally {
        setEstimating(false)
      }
    }
  }

  const handleSubmit = async () => {
    if (!selectedTemplate || !estimate?.audience_count || !launchReady) return
    setSubmitting(true)
    try {
      let headerMedia: { id?: string; link?: string; filename?: string } | undefined
      if (mediaFormat && mediaFile) {
        const uploaded = await uploadTemplateMedia(channel.id, mediaFile)
        headerMedia = { id: uploaded.media_id, filename: mediaFile.name }
      } else if (mediaFormat && mediaUrl.trim()) {
        headerMedia = { link: mediaUrl.trim() }
      }

      const values = paramValues.map((value, index) => resolveParamValue(paramSources[index], value))
      const payload = basePayload()
      payload.components = buildSendComponents(selectedTemplate.components, values, headerMedia)
      await createBroadcast(payload)
      addToast({
        type: "success",
        title: launch === "now" ? "Difusión encolada" : "Difusión programada",
        description: `${estimate.audience_count.toLocaleString("es-AR")} contactos · ${currency.format(estimate.estimated_cost_usd)}`,
      })
      await onCreated()
    } catch (error) {
      // El back pide tres confirmaciones distintas (consentimiento, messaging
      // limit, volumen) y las devuelve en cascada: cada una que el usuario ya
      // marcó viaja en el payload del reintento, así que un solo click más
      // adelante no reabre las anteriores.
      if (error instanceof BroadcastConfirmationRequiredError) {
        if (error.consentWarning) {
          setConsentRisks(error.consentWarning.risks)
          addToast({ type: "error", title: "Confirmá el riesgo de enviar sin consentimiento", description: error.message })
        } else if (error.messagingLimit) {
          addToast({ type: "error", title: "Supera el límite de mensajería de Meta", description: error.message })
        } else {
          setAudienceSizeConfirmationRequired(true)
          addToast({ type: "error", title: "Confirmá el tamaño de la audiencia", description: error.message })
        }
        return
      }
      addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo crear la difusión" })
    } finally {
      setSubmitting(false)
    }
  }

  const updateFilter = (index: number, patch: Partial<BroadcastFilter>) => {
    setCustomFilters((current) => current.map((filter, filterIndex) => filterIndex === index ? { ...filter, ...patch } : filter))
    setEstimate(null)
  }

  const toggleIncludeWithoutConsent = (checked: boolean) => {
    setIncludeWithoutConsent(checked)
    setAcknowledgeConsentRisk(false)
    setConsentRisks(null)
    setEstimate(null)
  }

  const toggleIncludedTag = (tagId: number) => {
    setTagIds((current) => current.includes(tagId) ? current.filter((id) => id !== tagId) : [...current, tagId])
    setExcludedTagIds((current) => current.filter((id) => id !== tagId))
    setEstimate(null)
  }

  const toggleExcludedTag = (tagId: number) => {
    setExcludedTagIds((current) => current.includes(tagId) ? current.filter((id) => id !== tagId) : [...current, tagId])
    setTagIds((current) => current.filter((id) => id !== tagId))
    setEstimate(null)
  }

  const toggleAllIncludedTags = () => {
    setTagIds(allIncludedTagsSelected ? [] : allTagIds)
    if (!allIncludedTagsSelected) setExcludedTagIds([])
    setEstimate(null)
  }

  const toggleAllExcludedTags = () => {
    setExcludedTagIds(allExcludedTagsSelected ? [] : allTagIds)
    if (!allExcludedTagsSelected) setTagIds([])
    setEstimate(null)
  }

  const durationSeconds = (estimate?.audience_count ?? 0) * intervalSeconds
  const durationLabel = intervalSeconds === 0
    ? "Envío inmediato"
    : durationSeconds >= 3600
      ? `Aprox. ${(durationSeconds / 3600).toFixed(1)} horas`
      : `Aprox. ${Math.max(1, Math.ceil(durationSeconds / 60))} minutos`

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[92vh] max-w-4xl flex-col overflow-hidden p-0 sm:max-w-4xl">
        <div className="border-b bg-[#0b3328] px-6 py-5 text-white">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-xl"><MegaphoneIcon /> Nueva difusión</DialogTitle>
            <DialogDescription className="text-emerald-50/70">Emisor: {channel.name} · configurá el envío en tres pasos</DialogDescription>
          </DialogHeader>
          <div className="mt-5 grid grid-cols-3 gap-2">
            {steps.map((label, index) => (
              <div key={label} className="flex items-center gap-2">
                <span className={cn("grid h-7 w-7 place-items-center rounded-full border text-xs font-semibold", index < step && "border-lime-300 bg-lime-300 text-emerald-950", index === step && "border-white bg-white text-emerald-950", index > step && "border-white/25 text-white/55")}>{index < step ? <Check className="h-3.5 w-3.5" /> : index + 1}</span>
                <span className={cn("hidden text-xs font-medium sm:block", index === step ? "text-white" : "text-white/55")}>{label}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-6">
          {step === 0 && (
            <div className="grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
              <div className="space-y-3">
                <div><h3 className="font-semibold">Elegí una plantilla aprobada</h3><p className="text-sm text-muted-foreground">Solo se muestran las que Meta habilitó para enviar.</p></div>
                <div className="space-y-2">
                  {approvedTemplates.map((template) => (
                    <button key={template.id} type="button" onClick={() => setTemplateId(template.id)} className={cn("w-full rounded-2xl border p-4 text-left transition-colors", template.id === templateId ? "border-emerald-500 bg-emerald-500/5 ring-2 ring-emerald-500/10" : "hover:bg-muted/50")}>
                      <div className="flex items-start justify-between gap-3"><div><p className="font-medium">{template.name}</p><p className="mt-1 text-xs text-muted-foreground">{template.language} · {template.category}</p></div>{template.id === templateId && <Check className="h-4 w-4 text-emerald-600" />}</div>
                    </button>
                  ))}
                  {approvedTemplates.length === 0 && <div className="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground">No hay plantillas aprobadas en este canal.</div>}
                </div>
              </div>

              {selectedTemplate && (
                <div className="space-y-4 rounded-3xl border bg-muted/25 p-5">
                  <div className="flex items-center gap-2"><MessageCircle className="h-4 w-4 text-emerald-600" /><p className="text-sm font-semibold">Vista previa y variables</p></div>
                  <div className="rounded-2xl rounded-tl-sm bg-white p-4 text-sm leading-6 text-slate-800 shadow-sm dark:bg-slate-900 dark:text-slate-100">
                    {buildTemplatePreview(selectedTemplate.components, paramValues.map((value, index) => paramSources[index] === "contact_name" ? "María" : paramSources[index] === "contact_phone" ? "+54 9 11…" : value)) || "Plantilla sin texto de cuerpo"}
                  </div>
                  {mediaFormat && <div className="space-y-2"><Label>Archivo de encabezado ({mediaFormat.toLowerCase()})</Label><div className="flex items-center gap-2"><Input type="file" onChange={(event) => setMediaFile(event.target.files?.[0] ?? null)} /><FileUp className="h-4 w-4 text-muted-foreground" /></div>{!mediaFile && <Input value={mediaUrl} onChange={(event) => setMediaUrl(event.target.value)} placeholder="o pegá una URL pública" />}</div>}
                  {bodyParams.names.map((paramName, index) => (
                    <div key={paramName} className="grid gap-2 sm:grid-cols-[150px_1fr]">
                      <div><Label>Variable {`{{${paramName}}}`}</Label><Select value={paramSources[index]} onValueChange={(value: ParamSource) => setParamSources((current) => current.map((source, sourceIndex) => sourceIndex === index ? value : source))}><SelectTrigger className="mt-1"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="contact_name">Nombre contacto</SelectItem><SelectItem value="contact_phone">Teléfono</SelectItem><SelectItem value="custom">Valor fijo</SelectItem></SelectContent></Select></div>
                      {paramSources[index] === "custom" && <div className="self-end"><Input value={paramValues[index]} onChange={(event) => setParamValues((current) => current.map((value, valueIndex) => valueIndex === index ? event.target.value : value))} placeholder="Valor para todos los contactos" /></div>}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {step === 1 && (
            <div className="space-y-6">
              <div className="grid gap-5 md:grid-cols-2">
                <div className="space-y-2"><Label htmlFor="broadcast-name">Nombre interno de la difusión</Label><Input id="broadcast-name" value={name} onChange={(event) => { setName(event.target.value); setEstimate(null) }} placeholder="Descuento 10% · lunes 08:00" /><p className="text-xs text-muted-foreground">Solo lo verá tu equipo; no se envía a Meta.</p></div>
                <div className="space-y-2"><Label>Etapa del pipeline</Label><Select value={stageId} onValueChange={(value) => { setStageId(value); setEstimate(null) }}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">Todas las etapas</SelectItem>{stages.map((stage) => <SelectItem key={stage.id} value={String(stage.id)}>{stage.name}</SelectItem>)}</SelectContent></Select></div>
              </div>

              <div className="space-y-4">
                <div>
                  <Label>Etiquetas CRM</Label>
                  <p className="text-xs text-muted-foreground">Incluí segmentos relevantes y excluí los que no deben recibir esta difusión.</p>
                </div>
                <div className="grid gap-4 rounded-2xl border p-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-medium">Incluir</p>
                        <p className="text-xs text-muted-foreground">Coincide con cualquiera de estas etiquetas.</p>
                      </div>
                      {tags.length > 0 ? <Button type="button" size="sm" variant="ghost" aria-pressed={allIncludedTagsSelected} onClick={toggleAllIncludedTags} className="h-7 shrink-0 px-2 text-xs">{allIncludedTagsSelected ? "Quitar todas" : "Todas"}</Button> : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {tags.map((tag) => {
                        const selected = tagIds.includes(tag.id)
                        return <button key={tag.id} type="button" aria-pressed={selected} onClick={() => toggleIncludedTag(tag.id)} className={cn("rounded-full border px-3 py-1.5 text-xs font-medium transition-colors", selected ? "border-emerald-500 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" : "hover:bg-muted")}>{selected ? <Check className="mr-1 inline h-3 w-3" /> : null}{tag.name}</button>
                      })}
                      {tags.length === 0 ? <span className="text-sm text-muted-foreground">No hay etiquetas creadas.</span> : null}
                    </div>
                  </div>
                  <div className="space-y-2 sm:border-l sm:pl-4">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-medium">Excluir</p>
                        <p className="text-xs text-muted-foreground">Estos contactos quedan fuera aunque coincidan con otros filtros.</p>
                      </div>
                      {tags.length > 0 ? <Button type="button" size="sm" variant="ghost" aria-pressed={allExcludedTagsSelected} onClick={toggleAllExcludedTags} className="h-7 shrink-0 px-2 text-xs">{allExcludedTagsSelected ? "Quitar todas" : "Todas"}</Button> : null}
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {tags.map((tag) => {
                        const selected = excludedTagIds.includes(tag.id)
                        return <button key={tag.id} type="button" aria-pressed={selected} onClick={() => toggleExcludedTag(tag.id)} className={cn("rounded-full border px-3 py-1.5 text-xs font-medium transition-colors", selected ? "border-red-500 bg-red-500/10 text-red-700 dark:text-red-300" : "hover:bg-muted")}>{selected ? <Trash2 className="mr-1 inline h-3 w-3" /> : null}{tag.name}</button>
                      })}
                      {tags.length === 0 ? <span className="text-sm text-muted-foreground">No hay etiquetas creadas.</span> : null}
                    </div>
                  </div>
                </div>
              </div>

              <div className="space-y-3 rounded-2xl border p-4">
                <div className="flex items-center justify-between"><div><Label>Campos del contacto</Label><p className="text-xs text-muted-foreground">Filtrá por ciudad, empresa u otro dato guardado.</p></div><Button type="button" size="sm" variant="outline" onClick={() => setCustomFilters((current) => [...current, { field: "name", operator: "contains", value: "" }])}><Plus className="mr-1 h-3.5 w-3.5" />Agregar filtro</Button></div>
                {customFilters.map((filter, index) => (
                  <div key={index} className="grid gap-2 md:grid-cols-[1fr_150px_1fr_40px]">
                    <Select value={filter.field} onValueChange={(value) => updateFilter(index, { field: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="name">Nombre</SelectItem><SelectItem value="phone">Teléfono</SelectItem><SelectItem value="email">Email</SelectItem><SelectItem value="source">Origen</SelectItem>{fields.map((field) => <SelectItem key={field.key} value={field.key}>{field.label}</SelectItem>)}</SelectContent></Select>
                    <Select value={filter.operator} onValueChange={(value: BroadcastFilter["operator"]) => updateFilter(index, { operator: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="contains">Contiene</SelectItem><SelectItem value="equals">Es igual a</SelectItem><SelectItem value="not_equals">No es igual a</SelectItem></SelectContent></Select>
                    <Input value={filter.value} onChange={(event) => updateFilter(index, { value: event.target.value })} placeholder="Mar del Plata" />
                    <Button type="button" size="icon" variant="ghost" onClick={() => { setCustomFilters((current) => current.filter((_, filterIndex) => filterIndex !== index)); setEstimate(null) }} aria-label="Eliminar filtro"><Trash2 className="h-4 w-4" /></Button>
                  </div>
                ))}
                {customFilters.length === 0 && <p className="py-3 text-center text-sm text-muted-foreground">Sin filtros de campo: se usará toda la audiencia con consentimiento del CRM.</p>}
              </div>

              {selectedTemplate?.category === "MARKETING" && (
                <div className="flex items-start gap-3 rounded-2xl border border-amber-300/50 bg-amber-50 p-4 dark:bg-amber-500/10">
                  <Checkbox id="include-without-consent" checked={includeWithoutConsent} onCheckedChange={(checked) => toggleIncludeWithoutConsent(checked === true)} className="mt-0.5" />
                  <div className="space-y-1">
                    <Label htmlFor="include-without-consent" className="cursor-pointer font-medium text-amber-900 dark:text-amber-200">Incluir contactos sin consentimiento registrado</Label>
                    <p className="text-xs leading-5 text-amber-800/80 dark:text-amber-200/70">
                      Meta exige que el contacto haya dado su consentimiento antes de recibir mensajes de marketing.
                      Sin él, tus números arriesgan bloqueos temporales o permanentes. Vas a tener que confirmarlo explícitamente en el siguiente paso.
                    </p>
                  </div>
                </div>
              )}
            </div>
          )}

          {step === 2 && estimate && (
            <div className="grid gap-6 lg:grid-cols-[1fr_0.9fr]">
              <div className="space-y-5">
                <div><h3 className="font-semibold">¿Cuándo querés lanzarla?</h3><p className="text-sm text-muted-foreground">Podés enviarla ahora o dejarla lista para otro momento.</p></div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <button type="button" onClick={() => setLaunch("now")} className={cn("rounded-2xl border p-4 text-left", launch === "now" && "border-emerald-500 bg-emerald-500/5 ring-2 ring-emerald-500/10")}><Send className="mb-3 h-5 w-5 text-emerald-600" /><p className="font-medium">Enviar ahora</p><p className="mt-1 text-xs text-muted-foreground">Se encola al confirmar.</p></button>
                  <button type="button" onClick={() => setLaunch("scheduled")} className={cn("rounded-2xl border p-4 text-left", launch === "scheduled" && "border-sky-500 bg-sky-500/5 ring-2 ring-sky-500/10")}><CalendarDays className="mb-3 h-5 w-5 text-sky-600" /><p className="font-medium">Programar</p><p className="mt-1 text-xs text-muted-foreground">Elegí fecha y hora.</p></button>
                </div>
                {launch === "scheduled" && <div className="space-y-2"><Label htmlFor="scheduled-at">Fecha y hora de lanzamiento</Label><Input id="scheduled-at" type="datetime-local" value={scheduledAt} onChange={(event) => setScheduledAt(event.target.value)} /></div>}
                <div className="space-y-2"><Label>Intervalo entre mensajes</Label><Select value={String(intervalSeconds)} onValueChange={(value) => setIntervalSeconds(Number(value) as BroadcastPayload["interval_seconds"])}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{intervals.map((interval) => <SelectItem key={interval.value} value={String(interval.value)}>{interval.label}</SelectItem>)}</SelectContent></Select><p className="flex items-center gap-1 text-xs text-muted-foreground"><Clock3 className="h-3.5 w-3.5" />{durationLabel}. Elegí un ritmo cómodo para responder.</p></div>
              </div>

              <div className="rounded-3xl bg-[#0b3328] p-6 text-white">
                <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200"><Sparkles className="h-4 w-4" />Resumen</div>
                <h3 className="mt-4 text-xl font-semibold">{name}</h3><p className="mt-1 text-sm text-emerald-50/65">{selectedTemplate?.name} · se envía desde {channel.name}</p>
                <div className="mt-6 grid grid-cols-2 gap-3"><div className="rounded-2xl bg-white/8 p-4"><Users className="mb-2 h-4 w-4 text-lime-300" /><p className="text-2xl font-semibold">{estimate.audience_count.toLocaleString("es-AR")}</p><p className="text-xs text-emerald-50/60">contactos estimados</p></div><div className="rounded-2xl bg-white/8 p-4"><span className="mb-2 block text-sm font-bold text-lime-300">USD</span><p className="text-2xl font-semibold">{currency.format(estimate.estimated_cost_usd).replace("US$", "")}</p><p className="text-xs text-emerald-50/60">costo estimado</p></div></div>
                <div className="mt-4 rounded-2xl border border-white/10 p-4 text-xs leading-5 text-emerald-50/70">Estimación calculada a USD 0,065 por mensaje. El gasto final refleja los envíos procesados.</div>

                {estimate.total_contacts_with_phone > estimate.audience_count && (
                  <p className="mt-3 text-xs leading-5 text-emerald-50/60">
                    {estimate.audience_count.toLocaleString("es-AR")} de {estimate.total_contacts_with_phone.toLocaleString("es-AR")} contactos del CRM entran en esta difusión.
                    {estimate.filters_applied.pipeline_stage_restricts_to_existing_conversations && " La etapa de pipeline elegida solo alcanza a contactos que ya tienen una conversación."}
                  </p>
                )}

                {estimate.contacts_without_conversation_count > 0 && (
                  <div className="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-emerald-50">
                      <Info className="h-4 w-4 shrink-0" />
                      Se van a crear {estimate.contacts_without_conversation_count.toLocaleString("es-AR")} conversaciones nuevas
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Esos contactos no hablaron antes con {channel.name}: van a recibir el mensaje desde un número que no conocen,
                      y su respuesta va a abrir una conversación nueva en tu bandeja.
                    </p>
                  </div>
                )}

                {estimate.without_consent_count > 0 && (
                  <div className="mt-3 rounded-2xl border border-amber-300/30 bg-amber-300/10 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-amber-200">
                      <AlertTriangle className="h-4 w-4 shrink-0" />
                      {estimate.without_consent_count.toLocaleString("es-AR")} sin consentimiento registrado
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Se incluyeron porque marcaste &ldquo;incluir contactos sin consentimiento&rdquo;. Confirmá el riesgo para poder enviar.
                    </p>
                    {consentRisks && (
                      <ul className="mt-2 space-y-1 text-xs leading-5 text-emerald-50/70">
                        {consentRisks.map((risk) => <li key={risk} className="flex gap-1.5"><span className="text-amber-300">·</span>{risk}</li>)}
                      </ul>
                    )}
                    <label className="mt-3 flex items-start gap-2 text-xs text-emerald-50">
                      <Checkbox checked={acknowledgeConsentRisk} onCheckedChange={(checked) => setAcknowledgeConsentRisk(checked === true)} className="mt-0.5 border-amber-200" />
                      Entiendo el riesgo y quiero enviarles igual.
                    </label>
                  </div>
                )}

                {estimate.excluded_duplicate_count > 0 && (
                  <div className="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-emerald-50">
                      <Info className="h-4 w-4 shrink-0" />
                      {estimate.excluded_duplicate_count.toLocaleString("es-AR")} {estimate.excluded_duplicate_count === 1 ? "contacto duplicado" : "contactos duplicados"}
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Tienen el mismo número guardado más de una vez: se dedujeron para no enviarles el mensaje dos veces.
                    </p>
                  </div>
                )}

                {estimate.capped && <Badge className="mt-3 bg-amber-300 text-amber-950">Audiencia limitada a 5.000 contactos</Badge>}

                {audienceSizeConfirmationRequired && (
                  <div className="mt-3 rounded-2xl border border-amber-300/30 bg-amber-300/10 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-amber-200">
                      <AlertTriangle className="h-4 w-4 shrink-0" />
                      Confirmá el volumen de esta difusión
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Vas a enviar {estimate.audience_count.toLocaleString("es-AR")} mensajes por un total estimado de {currency.format(estimate.estimated_cost_usd)}.
                    </p>
                    <label className="mt-3 flex items-start gap-2 text-xs text-emerald-50">
                      <Checkbox checked={acknowledgeAudienceSize} onCheckedChange={(checked) => setAcknowledgeAudienceSize(checked === true)} className="mt-0.5 border-amber-200" />
                      Confirmo el envío a esta cantidad de contactos.
                    </label>
                  </div>
                )}

                {estimate.messaging_limit.exceeded && estimate.messaging_limit.limit !== null && (
                  <div className="mt-3 rounded-2xl border border-amber-300/30 bg-amber-300/10 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-amber-200">
                      <AlertTriangle className="h-4 w-4 shrink-0" />
                      Supera tu límite de Meta
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Meta te permite {estimate.messaging_limit.limit.toLocaleString("es-AR")} destinatarios únicos cada 24 horas.
                      Los {(estimate.audience_count - estimate.messaging_limit.limit).toLocaleString("es-AR")} restantes van a fallar.
                      El límite se comparte entre todos los números de tu cuenta, no es exclusivo de {channel.name}.
                    </p>
                    <label className="mt-3 flex items-start gap-2 text-xs text-emerald-50">
                      <Checkbox checked={acknowledgeMessagingLimit} onCheckedChange={(checked) => setAcknowledgeMessagingLimit(checked === true)} className="mt-0.5 border-amber-200" />
                      Entiendo que parte de los mensajes va a fallar y quiero enviar igual.
                    </label>
                  </div>
                )}

                {estimate.excluded_us_count > 0 && (
                  <div className="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-emerald-50">
                      <Info className="h-4 w-4 shrink-0" />
                      {estimate.excluded_us_count.toLocaleString("es-AR")} {estimate.excluded_us_count === 1 ? "contacto excluido" : "contactos excluidos"}
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Meta no entrega plantillas de marketing a números de Estados Unidos, así que
                      {estimate.excluded_us_count === 1 ? " ese contacto queda fuera" : " esos contactos quedan fuera"} de la difusión.
                      Con plantillas de utilidad o autenticación sí los alcanzás.
                    </p>
                  </div>
                )}

                {durationSeconds > 3600 && (
                  <div className="mt-3 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p className="flex items-center gap-2 text-sm font-medium text-emerald-50">
                      <Clock3 className="h-4 w-4 shrink-0" />
                      Esta difusión va a tardar {durationLabel.toLowerCase()}
                    </p>
                    <p className="mt-1.5 text-xs leading-5 text-emerald-50/70">
                      Con este intervalo, el último mensaje sale bastante después del primero. Si necesitás que llegue antes, elegí un intervalo más corto.
                    </p>
                  </div>
                )}

                {!estimate.messaging_limit.known && (
                  <p className="mt-3 text-xs leading-5 text-emerald-50/55">
                    No se pudo leer tu límite de envío de Meta, así que no verificamos si esta audiencia lo supera.
                  </p>
                )}
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="flex-row items-center justify-between border-t px-6 py-4 sm:justify-between">
          <Button variant="ghost" onClick={() => step === 0 ? onOpenChange(false) : setStep((current) => current - 1)}>{step > 0 && <ArrowLeft className="mr-2 h-4 w-4" />}{step === 0 ? "Cancelar" : "Atrás"}</Button>
          {step < 2 ? (
            <Button onClick={goForward} disabled={(step === 0 && !templateReady) || (step === 1 && !audienceReady) || estimating}>
              {estimating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <ArrowRight className="mr-2 h-4 w-4" />}
              {step === 1 ? "Calcular audiencia" : "Continuar"}
            </Button>
          ) : (
            <Button
              onClick={handleSubmit}
              disabled={
                !estimate?.audience_count
                || !launchReady
                || submitting
                || (Boolean(estimate?.without_consent_count) && !acknowledgeConsentRisk)
                || (Boolean(estimate?.messaging_limit.exceeded) && !acknowledgeMessagingLimit)
                || (audienceSizeConfirmationRequired && !acknowledgeAudienceSize)
              }
              className="bg-emerald-600 hover:bg-emerald-700"
            >
              {submitting ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
              {launch === "now" ? "Confirmar y enviar" : "Programar difusión"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function MegaphoneIcon() {
  return <span className="grid h-8 w-8 place-items-center rounded-xl bg-lime-300 text-emerald-950"><Send className="h-4 w-4" /></span>
}
