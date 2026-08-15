"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import {
  AlertTriangle,
  ArrowDown,
  Braces,
  CalendarClock,
  CheckCircle2,
  Clock3,
  Eye,
  History,
  Loader2,
  Pause,
  Pencil,
  Play,
  Plus,
  RotateCcw,
  Trash2,
  Workflow,
  X,
} from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { useToast } from "@/components/Toast"
import { Channel, WhatsAppTemplate } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import {
  activateAutomation,
  createAutomation,
  deleteAutomation,
  getAutomationRuns,
  getAutomations,
  pauseAutomation,
  previewAutomation,
  retryAutomationRun,
  updateAutomation,
  type AutomationAction,
  type AutomationPayload,
  type AutomationRule,
  type AutomationRun,
  type AutomationRunStatus,
} from "@/lib/api/automations"
import { getChannels } from "@/lib/api/channels"
import { getContactFields, type ContactFieldsResponse } from "@/lib/api/contact-fields"
import { uploadMediaAsset } from "@/lib/api/media-assets"
import { getManagedTemplates } from "@/lib/api/templates"
import { cn } from "@/lib/utils"

type LeafCondition = { field: string; operator: string; value: string }
type ConditionGroup = { operator: "AND" | "OR"; conditions: Array<LeafCondition | ConditionGroup> }
type FieldOptionGroup = { label: string; options: Array<{ value: string; label: string }> }

const triggers = [
  ["contact.created", "contactCreated"],
  ["contact.field_changed", "contactChanged"],
  ["conversation.created", "conversationCreated"],
  ["conversation.stage_changed", "stageChanged"],
  ["conversation.status_changed", "statusChanged"],
  ["date.reached", "dateReached"],
] as const

const operators = ["equals", "not_equals", "contains", "empty", "not_empty", "greater_than", "less_than", "in", "has_tag", "stage_is"]

type ActionParameter = NonNullable<AutomationAction["config"]["parameters"]>[number]

// Nombres de las variables {{...}} del body del template, en orden y sin
// repetir. Debe contar lo mismo que WhatsAppTemplate::expectedBodyParameters()
// en el backend, o el formulario prellenaría algo que la validación rechaza.
function templateBodyParameters(template: WhatsAppTemplate | undefined): string[] {
  const body = template?.components?.find((component) => component.type?.toUpperCase() === "BODY")
  if (!body?.text) return []
  const names = [...body.text.matchAll(/\{\{\s*([^}]+?)\s*\}\}/g)].map((match) => match[1])
  return [...new Set(names)]
}

// Formato del header cuando es de media (DOCUMENT/IMAGE/VIDEO), o null.
// Debe coincidir con WhatsAppTemplate::headerMediaFormat() del backend.
function templateHeaderMediaFormat(template: WhatsAppTemplate | undefined): string | null {
  const header = template?.components?.find((component) => component.type?.toUpperCase() === "HEADER")
  const format = header?.format?.toUpperCase()
  return format === "DOCUMENT" || format === "IMAGE" || format === "VIDEO" ? format : null
}

// Al elegir un template se prellenan sus parámetros mapeados al nombre del
// contacto, que es el caso más común; el usuario ajusta fuente/ruta después.
// Si el template tiene un header con archivo, se agrega un parámetro de
// encabezado en blanco para que el usuario cargue la URL del documento.
function parametersForTemplate(template: WhatsAppTemplate | undefined): ActionParameter[] {
  const params: ActionParameter[] = templateBodyParameters(template).map((name) => ({
    component: "body",
    name,
    source: "field",
    path: "contact.name",
    fallback: "Cliente",
  }))
  if (templateHeaderMediaFormat(template)) {
    params.unshift({ component: "header", source: "media_asset" })
  }
  return params
}
const emptyGroup = (): ConditionGroup => ({ operator: "AND", conditions: [] })
const emptyAction = (): AutomationAction => ({ type: "whatsapp_template", config: { parameters: [] } })

// El backend valida contra DateTimeZone::ALL, que rechaza los alias legacy de
// la IANA que algunos navegadores todavía reportan.
const TIMEZONE_ALIASES: Record<string, string> = {
  "America/Buenos_Aires": "America/Argentina/Buenos_Aires",
}

function normalizeTimezone(timezone: string): string {
  return TIMEZONE_ALIASES[timezone] ?? timezone
}

const initialPayload = (): AutomationPayload => ({
  name: "",
  trigger_type: "contact.created",
  trigger_config: {},
  conditions: null,
  timezone: normalizeTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC"),
  actions: [emptyAction()],
})

export function AutomationsSettings() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [rules, setRules] = useState<AutomationRule[]>([])
  const [channels, setChannels] = useState<Channel[]>([])
  const [contactFields, setContactFields] = useState<ContactFieldsResponse>({ data: [], standard: [] })
  const [templates, setTemplates] = useState<Record<number, WhatsAppTemplate[]>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [builderOpen, setBuilderOpen] = useState(false)
  const [editing, setEditing] = useState<AutomationRule | null>(null)
  const [form, setForm] = useState<AutomationPayload>(initialPayload)
  const [conditionGroup, setConditionGroup] = useState<ConditionGroup>(emptyGroup)
  const [runsRule, setRunsRule] = useState<AutomationRule | null>(null)
  const [runs, setRuns] = useState<AutomationRun[]>([])
  const [previewRule, setPreviewRule] = useState<AutomationRule | null>(null)
  const [previewSubject, setPreviewSubject] = useState({ type: "contact", id: "" })
  const [preview, setPreview] = useState<Record<string, unknown> | null>(null)

  const timezones = useMemo(() => {
    try {
      return [...new Set(Intl.supportedValuesOf("timeZone").map(normalizeTimezone))].sort()
    } catch { return [] }
  }, [])
  const actionsReady = form.actions.every((action) => Boolean(action.config.channel_id && action.config.template_id))
  const contactFieldOptions = useMemo(() => [
    ...contactFields.standard.map((field) => ({ value: field.key, label: field.label })),
    ...contactFields.data.map((field) => ({ value: `custom_data.${field.key}`, label: field.label })),
  ], [contactFields])
  // Campos que pueden alimentar el header de un template: los de tipo archivo
  // (el PDF vive en la app, URL pública garantizada) y los de tipo URL (link
  // externo, se verifica al enviar). La ruta es la que resuelve el backend.
  const documentFieldOptions = useMemo(() =>
    contactFields.data
      .filter((field) => field.type === "file" || field.type === "url")
      .map((field) => ({ path: `contact.custom_data.${field.key}`, label: field.label, type: field.type })),
  [contactFields])
  const conditionFieldOptions = useMemo<FieldOptionGroup[]>(() => [
    {
      label: t("settings.automations.contact"),
      options: [
        ...contactFieldOptions.map((option) => ({ value: `contact.${option.value}`, label: option.label })),
        { value: "contact.tags", label: t("settings.automations.tags") },
      ],
    },
    {
      label: t("settings.automations.conversation"),
      options: [
        { value: "conversation.status", label: t("settings.automations.conversationStatus") },
        { value: "conversation.pipeline_stage_id", label: t("settings.automations.pipelineStage") },
        { value: "conversation.created_at", label: t("settings.automations.creationDate") },
      ],
    },
    {
      label: t("settings.automations.eventChange"),
      options: [
        { value: "old.status", label: t("settings.automations.oldStatus") },
        { value: "new.status", label: t("settings.automations.newStatus") },
      ],
    },
  ], [contactFieldOptions, t])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [automationData, channelData, fieldData] = await Promise.all([
        getAutomations(),
        getChannels(),
        // Sin permiso de campos el builder sigue funcionando con entrada libre.
        getContactFields().catch(() => ({ data: [], standard: [] }) as ContactFieldsResponse),
      ])
      setRules(automationData)
      setChannels(channelData.filter((channel) => Number(channel.type) === 1))
      setContactFields(fieldData)
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    } finally {
      setLoading(false)
    }
  }, [addToast, t])

  useEffect(() => { void load() }, [load])

  const loadTemplates = async (channelId: number) => {
    if (templates[channelId]) return
    try {
      const data = await getManagedTemplates(channelId)
      setTemplates((current) => ({ ...current, [channelId]: data }))
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    }
  }

  const openCreate = () => {
    setEditing(null)
    setForm(initialPayload())
    setConditionGroup(emptyGroup())
    setBuilderOpen(true)
  }

  const openEdit = (rule: AutomationRule) => {
    setEditing(rule)
    setForm({
      name: rule.name,
      trigger_type: rule.trigger_type,
      trigger_config: rule.trigger_config,
      conditions: rule.conditions,
      timezone: rule.timezone,
      actions: rule.actions.map((action) => ({ type: action.type, config: { ...action.config, parameters: action.config.parameters ?? [] } })),
    })
    setConditionGroup((rule.conditions as ConditionGroup | null) ?? emptyGroup())
    rule.actions.forEach((action) => { if (action.config.channel_id) void loadTemplates(action.config.channel_id) })
    setBuilderOpen(true)
  }

  const save = async () => {
    if (!form.name.trim()) return
    setSaving(true)
    try {
      const payload = { ...form, name: form.name.trim(), timezone: normalizeTimezone(form.timezone), conditions: conditionGroup.conditions.length ? conditionGroup : null }
      if (editing) await updateAutomation(editing.id, payload)
      else await createAutomation(payload)
      addToast({ type: "success", title: t(editing ? "settings.automations.updated" : "settings.automations.created") })
      setBuilderOpen(false)
      await load()
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    } finally {
      setSaving(false)
    }
  }

  const mutateStatus = async (rule: AutomationRule) => {
    try {
      if (rule.status === "active") await pauseAutomation(rule.id)
      else await activateAutomation(rule.id)
      await load()
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    }
  }

  const remove = async (rule: AutomationRule) => {
    if (!confirm(t("settings.automations.confirmDelete"))) return
    try {
      await deleteAutomation(rule.id)
      await load()
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    }
  }

  const openRuns = async (rule: AutomationRule) => {
    setRunsRule(rule)
    setRuns([])
    try {
      setRuns(await getAutomationRuns(rule.id))
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    }
  }

  const runPreview = async () => {
    if (!previewRule || !previewSubject.id) return
    try {
      setPreview(await previewAutomation(previewRule.id, previewSubject.type, Number(previewSubject.id)))
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    }
  }

  const retryRun = async (run: AutomationRun) => {
    if (!runsRule) return
    try {
      await retryAutomationRun(run.id)
      setRuns(await getAutomationRuns(runsRule.id))
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    }
  }

  return (
    <SettingsBlock
      title={t("settings.automations.title")}
      description={t("settings.automations.description")}
      icon={Workflow}
      action={
        <Button onClick={openCreate}><Plus className="mr-2 size-4" />{t("settings.automations.new")}</Button>
      }
    >
      <div>
        {loading ? (
          <div className="flex items-center justify-center py-16"><Loader2 className="size-5 animate-spin text-muted-foreground" /></div>
        ) : rules.length === 0 ? (
          <div className="flex max-w-md flex-col items-start border-y border-dashed border-border py-12">
            <div className="mb-4 flex size-12 items-center justify-center rounded-full border border-dashed border-primary/50 bg-primary/5"><Workflow className="size-5 text-primary" /></div>
            <p className="font-medium">{t("settings.automations.emptyTitle")}</p>
            <p className="mt-1 text-sm text-muted-foreground">{t("settings.automations.empty")}</p>
          </div>
        ) : (
          <div className="divide-y divide-border border-y border-border">
            {rules.map((rule) => (
              <article key={rule.id} className="group grid gap-4 py-4 transition-colors hover:bg-muted/20 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="truncate font-medium">{rule.name}</h3>
                    <StatusBadge status={rule.status} label={t(`settings.automations.status.${rule.status}`)} />
                    <span className="text-xs text-muted-foreground">v{rule.version}</span>
                  </div>
                  <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1.5"><Workflow className="size-3.5" />{t(`settings.automations.triggers.${triggers.find(([id]) => id === rule.trigger_type)?.[1] ?? "contactCreated"}`)}</span>
                    <span>{rule.actions.length} {t("settings.automations.actions")}</span>
                    <span>{rule.runs_count ?? 0} {t("settings.automations.executions")}</span>
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                  <Button variant="ghost" size="sm" onClick={() => { setPreviewRule(rule); setPreview(null) }}><Eye className="mr-1.5 size-4" />{t("settings.automations.preview")}</Button>
                  <Button variant="ghost" size="sm" onClick={() => void openRuns(rule)}><History className="mr-1.5 size-4" />{t("settings.automations.history")}</Button>
                  <Button variant="ghost" size="icon" aria-label={t("common.edit")} onClick={() => openEdit(rule)}><Pencil className="size-4" /></Button>
                  <Button variant="ghost" size="icon" aria-label={rule.status === "active" ? t("settings.automations.pause") : t("settings.automations.activate")} onClick={() => void mutateStatus(rule)}>
                    {rule.status === "active" ? <Pause className="size-4" /> : <Play className="size-4" />}
                  </Button>
                  <Button variant="ghost" size="icon" disabled={rule.status === "active"} aria-label={t("common.delete")} onClick={() => void remove(rule)}><Trash2 className="size-4 text-destructive" /></Button>
                </div>
              </article>
            ))}
          </div>
        )}
      </div>

      <Dialog open={builderOpen} onOpenChange={(open) => { if (!saving) setBuilderOpen(open) }}>
        <DialogContent className="max-h-[92vh] max-w-5xl overflow-y-auto p-0">
          <DialogHeader className="sticky top-0 z-10 border-b border-border bg-background px-6 py-5">
            <DialogTitle>{t(editing ? "settings.automations.edit" : "settings.automations.create")}</DialogTitle>
            <DialogDescription>{t("settings.automations.builderDescription")}</DialogDescription>
          </DialogHeader>
          <div className="space-y-8 px-6 py-6">
            <div className="grid gap-4 md:grid-cols-[1fr_220px]">
              <Field label={t("settings.automations.name")}><Input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} placeholder={t("settings.automations.namePlaceholder")} /></Field>
              <Field label={t("settings.automations.timezone")}><Input list="automation-timezones" value={form.timezone} onChange={(event) => setForm((current) => ({ ...current, timezone: event.target.value }))} /><datalist id="automation-timezones">{timezones.map((zone) => <option key={zone} value={zone} />)}</datalist></Field>
            </div>

            <BuilderStep number="1" title={t("settings.automations.trigger")} icon={Play}>
              <TriggerEditor form={form} onChange={setForm} fieldOptions={contactFieldOptions} t={t} />
            </BuilderStep>

            <BuilderStep number="2" title={t("settings.automations.conditions")} icon={Braces} optional={t("settings.automations.optional")}>
              <ConditionEditor group={conditionGroup} onChange={setConditionGroup} fieldOptions={conditionFieldOptions} t={t} />
            </BuilderStep>

            <BuilderStep number="3" title={t("settings.automations.actionSequence")} icon={ArrowDown}>
              <div className="space-y-3">
                {form.actions.map((action, index) => (
                  <ActionEditor
                    key={index}
                    index={index}
                    action={action}
                    channels={channels}
                    templates={templates}
                    documentFieldOptions={documentFieldOptions}
                    t={t}
                    onLoadTemplates={loadTemplates}
                    onChange={(next) => setForm((current) => ({ ...current, actions: current.actions.map((item, itemIndex) => itemIndex === index ? next : item) }))}
                    onRemove={() => setForm((current) => ({ ...current, actions: current.actions.filter((_, itemIndex) => itemIndex !== index) }))}
                  />
                ))}
                <Button variant="outline" size="sm" onClick={() => setForm((current) => ({ ...current, actions: [...current.actions, emptyAction()] }))}><Plus className="mr-2 size-4" />{t("settings.automations.addAction")}</Button>
              </div>
            </BuilderStep>
          </div>
          <DialogFooter className="sticky bottom-0 border-t border-border bg-background px-6 py-4">
            {!actionsReady && form.actions.length > 0 ? <p className="self-center text-xs text-muted-foreground sm:mr-auto">{t("settings.automations.actionIncomplete")}</p> : null}
            <Button variant="outline" disabled={saving} onClick={() => setBuilderOpen(false)}>{t("common.cancel")}</Button>
            <Button onClick={() => void save()} disabled={saving || !form.name.trim() || form.actions.length === 0 || !actionsReady}>{saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}{t("settings.save")}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <PreviewDialog rule={previewRule} value={previewSubject} preview={preview} t={t} onValueChange={setPreviewSubject} onRun={runPreview} onClose={() => setPreviewRule(null)} />
      <RunsDialog rule={runsRule} runs={runs} t={t} onClose={() => setRunsRule(null)} onRetry={retryRun} />
    </SettingsBlock>
  )
}

function TriggerEditor({ form, onChange, fieldOptions, t }: { form: AutomationPayload; onChange: (value: AutomationPayload) => void; fieldOptions: Array<{ value: string; label: string }>; t: (key: string) => string }) {
  const config = form.trigger_config
  const setConfig = (patch: Record<string, unknown>) => onChange({ ...form, trigger_config: { ...config, ...patch } })
  const watchedField = String(config.field ?? "")
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Field label={t("settings.automations.event")}>
        <Select value={form.trigger_type} onValueChange={(value) => onChange({ ...form, trigger_type: value, trigger_config: value === "date.reached" ? { subject: "contact", field: "created_at", offset_value: 0, offset_unit: "days", offset_direction: "after", local_time: "09:00" } : {} })}>
          <SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{triggers.map(([id, key]) => <SelectItem key={id} value={id}>{t(`settings.automations.triggers.${key}`)}</SelectItem>)}</SelectContent>
        </Select>
      </Field>
      {form.trigger_type === "contact.field_changed" ? (
        <Field label={t("settings.automations.watchedField")}>
          {fieldOptions.length > 0 ? (
            <Select value={watchedField} onValueChange={(value) => setConfig({ field: value })}>
              <SelectTrigger><SelectValue placeholder={t("settings.automations.selectField")} /></SelectTrigger>
              <SelectContent>
                {fieldOptions.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}
                {watchedField && !fieldOptions.some((option) => option.value === watchedField) ? <SelectItem value={watchedField}>{watchedField}</SelectItem> : null}
              </SelectContent>
            </Select>
          ) : (
            <Input value={watchedField} onChange={(event) => setConfig({ field: event.target.value })} placeholder="custom_data.fecha_renovacion" />
          )}
        </Field>
      ) : null}
      {form.trigger_type === "date.reached" ? (
        <>
          <Field label={t("settings.automations.subject")}><Select value={String(config.subject ?? "contact")} onValueChange={(value) => setConfig({ subject: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="contact">{t("settings.automations.contact")}</SelectItem><SelectItem value="conversation">{t("settings.automations.conversation")}</SelectItem></SelectContent></Select></Field>
          <Field label={t("settings.automations.dateField")}><Input value={String(config.field ?? "")} onChange={(event) => setConfig({ field: event.target.value })} placeholder="custom_data.fecha_renovacion" /></Field>
          <Field label={t("settings.automations.offset")}><div className="grid grid-cols-[80px_1fr_1fr] gap-2"><Input type="number" min={0} value={Number(config.offset_value ?? 0)} onChange={(event) => setConfig({ offset_value: Number(event.target.value) })} /><Select value={String(config.offset_unit ?? "days")} onValueChange={(value) => setConfig({ offset_unit: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="days">{t("settings.automations.days")}</SelectItem><SelectItem value="weeks">{t("settings.automations.weeks")}</SelectItem></SelectContent></Select><Select value={String(config.offset_direction ?? "after")} onValueChange={(value) => setConfig({ offset_direction: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="before">{t("settings.automations.before")}</SelectItem><SelectItem value="after">{t("settings.automations.after")}</SelectItem></SelectContent></Select></div></Field>
          <Field label={t("settings.automations.localTime")}><Input type="time" value={String(config.local_time ?? "09:00")} onChange={(event) => setConfig({ local_time: event.target.value })} /></Field>
          <div className="rounded-lg border border-border p-4 md:col-span-2">
            <div className="flex items-center justify-between"><div><p className="text-sm font-medium">{t("settings.automations.recurrence")}</p><p className="text-xs text-muted-foreground">{t("settings.automations.recurrenceHint")}</p></div><Switch checked={Boolean((config.recurrence as Record<string, unknown> | undefined)?.enabled)} onCheckedChange={(enabled) => setConfig({ recurrence: { every: 1, unit: "weeks", max_occurrences: 4, ...(config.recurrence as Record<string, unknown> | undefined), enabled } })} /></div>
            {Boolean((config.recurrence as Record<string, unknown> | undefined)?.enabled) ? <div className="mt-4 grid gap-3 md:grid-cols-2"><Field label={t("settings.automations.every")}><div className="grid grid-cols-[80px_1fr] gap-2"><Input type="number" min={1} value={Number((config.recurrence as Record<string, unknown>).every ?? 1)} onChange={(event) => setConfig({ recurrence: { ...(config.recurrence as object), every: Number(event.target.value) } })} /><Select value={String((config.recurrence as Record<string, unknown>).unit ?? "weeks")} onValueChange={(unit) => setConfig({ recurrence: { ...(config.recurrence as object), unit } })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="days">{t("settings.automations.days")}</SelectItem><SelectItem value="weeks">{t("settings.automations.weeks")}</SelectItem></SelectContent></Select></div></Field><Field label={t("settings.automations.maxOccurrences")}><Input type="number" min={1} value={Number((config.recurrence as Record<string, unknown>).max_occurrences ?? 4)} onChange={(event) => setConfig({ recurrence: { ...(config.recurrence as object), max_occurrences: Number(event.target.value) } })} /></Field></div> : null}
          </div>
        </>
      ) : null}
    </div>
  )
}

function ConditionEditor({ group, onChange, fieldOptions, t, depth = 0 }: { group: ConditionGroup; onChange: (group: ConditionGroup) => void; fieldOptions: FieldOptionGroup[]; t: (key: string) => string; depth?: number }) {
  return (
    <div className={cn("space-y-3 rounded-xl border border-border p-3", depth > 0 && "ml-4 bg-muted/30")}>
      <div className="flex items-center justify-between gap-2"><Select value={group.operator} onValueChange={(operator: "AND" | "OR") => onChange({ ...group, operator })}><SelectTrigger className="h-8 w-28"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="AND">AND</SelectItem><SelectItem value="OR">OR</SelectItem></SelectContent></Select>{depth > 0 ? <Badge variant="outline">{t("settings.automations.group")}</Badge> : null}</div>
      {group.conditions.map((condition, index) => "conditions" in condition ? (
        <div key={index} className="flex items-start gap-2"><div className="min-w-0 flex-1"><ConditionEditor depth={depth + 1} group={condition} onChange={(next) => onChange({ ...group, conditions: group.conditions.map((item, itemIndex) => itemIndex === index ? next : item) })} fieldOptions={fieldOptions} t={t} /></div><Button variant="ghost" size="icon" aria-label={t("settings.automations.removeGroup")} onClick={() => onChange({ ...group, conditions: group.conditions.filter((_, itemIndex) => itemIndex !== index) })}><X className="size-4" /></Button></div>
      ) : (
        <div key={index} className="grid gap-2 rounded-lg bg-muted/30 p-2 md:grid-cols-[1.2fr_150px_1fr_auto]">
          <Select value={condition.field} onValueChange={(field) => onChange({ ...group, conditions: group.conditions.map((item, itemIndex) => itemIndex === index ? { ...condition, field } : item) })}>
            <SelectTrigger><SelectValue placeholder={t("settings.automations.selectField")} /></SelectTrigger>
            <SelectContent>
              {fieldOptions.map((optionGroup) => (
                <SelectGroup key={optionGroup.label}>
                  <SelectLabel>{optionGroup.label}</SelectLabel>
                  {optionGroup.options.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}
                </SelectGroup>
              ))}
              {condition.field && !fieldOptions.some((optionGroup) => optionGroup.options.some((option) => option.value === condition.field)) ? <SelectItem value={condition.field}>{condition.field}</SelectItem> : null}
            </SelectContent>
          </Select>
          <Select value={condition.operator} onValueChange={(operator) => onChange({ ...group, conditions: group.conditions.map((item, itemIndex) => itemIndex === index ? { ...condition, operator } : item) })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{operators.map((operator) => <SelectItem key={operator} value={operator}>{t(`settings.automations.operators.${operator}`)}</SelectItem>)}</SelectContent></Select>
          <Input disabled={["empty", "not_empty"].includes(condition.operator)} value={condition.value} onChange={(event) => onChange({ ...group, conditions: group.conditions.map((item, itemIndex) => itemIndex === index ? { ...condition, value: event.target.value } : item) })} placeholder={t("settings.automations.value")} />
          <Button variant="ghost" size="icon" aria-label={t("settings.automations.removeCondition")} onClick={() => onChange({ ...group, conditions: group.conditions.filter((_, itemIndex) => itemIndex !== index) })}><X className="size-4" /></Button>
        </div>
      ))}
      {depth === 0 && group.conditions.length === 0 ? <p className="text-xs text-muted-foreground">{t("settings.automations.noConditions")}</p> : null}
      <div className="flex flex-wrap gap-2"><Button variant="outline" size="sm" onClick={() => onChange({ ...group, conditions: [...group.conditions, { field: "contact.name", operator: "equals", value: "" }] })}><Plus className="mr-1 size-3.5" />{t("settings.automations.addCondition")}</Button>{depth < 2 ? <Button variant="ghost" size="sm" onClick={() => onChange({ ...group, conditions: [...group.conditions, emptyGroup()] })}><Braces className="mr-1 size-3.5" />{t("settings.automations.addGroup")}</Button> : null}</div>
    </div>
  )
}

type DocumentFieldOption = { path: string; label: string; type: string }

function ActionEditor({ index, action, channels, templates, documentFieldOptions, onLoadTemplates, onChange, onRemove, t }: { index: number; action: AutomationAction; channels: Channel[]; templates: Record<number, WhatsAppTemplate[]>; documentFieldOptions: DocumentFieldOption[]; onLoadTemplates: (id: number) => Promise<void>; onChange: (action: AutomationAction) => void; onRemove: () => void; t: (key: string) => string }) {
  const config = action.config
  const parameters = config.parameters ?? []
  const selectedTemplate = (templates[config.channel_id ?? 0] ?? []).find((item) => item.id === config.template_id)
  const expectedBodyParams = templateBodyParameters(selectedTemplate)
  const bodyParamCount = parameters.filter((parameter) => (parameter.component ?? "body") === "body").length
  const paramCountMismatch = Boolean(config.template_id) && bodyParamCount !== expectedBodyParams.length
  const headerMediaFormat = templateHeaderMediaFormat(selectedTemplate)
  const headerParamCount = parameters.filter((parameter) => parameter.component === "header").length
  const headerMediaMissing = Boolean(config.template_id) && headerMediaFormat !== null && headerParamCount !== 1
  return (
    <div className="relative rounded-xl border border-border bg-card p-4 shadow-sm">
      <div className="mb-4 flex items-center justify-between"><div className="flex items-center gap-2"><span className="flex size-6 items-center justify-center rounded-full border border-border bg-muted text-xs font-semibold">{index + 1}</span><p className="text-sm font-medium">{t("settings.automations.whatsappTemplate")}</p></div><Button variant="ghost" size="icon" aria-label={t("settings.automations.removeAction")} disabled={index === 0} onClick={onRemove}><Trash2 className="size-4 text-destructive" /></Button></div>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t("settings.automations.channel")}><Select value={config.channel_id ? String(config.channel_id) : ""} onValueChange={(value) => { const channelId = Number(value); onChange({ ...action, config: { ...config, channel_id: channelId, template_id: undefined } }); void onLoadTemplates(channelId) }}><SelectTrigger><SelectValue placeholder={t("settings.automations.selectChannel")} /></SelectTrigger><SelectContent>{channels.map((channel) => <SelectItem key={channel.id} value={String(channel.id)}>{channel.name}</SelectItem>)}</SelectContent></Select></Field>
        <Field label={t("settings.automations.template")}><Select disabled={!config.channel_id} value={config.template_id ? String(config.template_id) : ""} onValueChange={(value) => { const templateId = Number(value); const template = (templates[config.channel_id ?? 0] ?? []).find((item) => item.id === templateId); const autofilled = parametersForTemplate(template); onChange({ ...action, config: { ...config, template_id: templateId, parameters: parameters.length ? parameters : autofilled } }) }}><SelectTrigger><SelectValue placeholder={t("settings.automations.selectTemplate")} /></SelectTrigger><SelectContent>{(templates[config.channel_id ?? 0] ?? []).map((template) => <SelectItem key={template.id} value={String(template.id)}>{template.name} · {template.language}</SelectItem>)}</SelectContent></Select></Field>
      </div>
      <div className="mt-5 border-t border-border pt-4">
        <div className="mb-3 flex items-center justify-between"><div><p className="text-sm font-medium">{t("settings.automations.parameters")}</p><p className="text-xs text-muted-foreground">{t("settings.automations.parametersHint")}</p></div><Button variant="outline" size="sm" onClick={() => onChange({ ...action, config: { ...config, parameters: [...parameters, { component: "body", source: "field", path: "contact.name", fallback: "Cliente" }] } })}><Plus className="mr-1 size-3.5" />{t("settings.automations.parameter")}</Button></div>
        {paramCountMismatch && <p className="mb-3 rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{t("settings.automations.parameterCountMismatch").replace("{expected}", String(expectedBodyParams.length)).replace("{provided}", String(bodyParamCount))}</p>}
        {headerMediaMissing && <p className="mb-3 rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{t("settings.automations.headerMediaMissing")}</p>}
        <div className="space-y-2">{parameters.map((parameter, parameterIndex) => <ParameterRow key={parameterIndex} parameter={parameter} documentFieldOptions={documentFieldOptions} onChange={(next) => onChange({ ...action, config: { ...config, parameters: parameters.map((item, itemIndex) => itemIndex === parameterIndex ? next : item) } })} onRemove={() => onChange({ ...action, config: { ...config, parameters: parameters.filter((_, itemIndex) => itemIndex !== parameterIndex) } })} t={t} />)}</div>
      </div>
    </div>
  )
}

function ParameterRow({ parameter, documentFieldOptions, onChange, onRemove, t }: { parameter: ActionParameter; documentFieldOptions: DocumentFieldOption[]; onChange: (next: ActionParameter) => void; onRemove: () => void; t: (key: string) => string }) {
  const [uploading, setUploading] = useState(false)
  const [uploadedName, setUploadedName] = useState<string | null>(null)
  const [uploadError, setUploadError] = useState<string | null>(null)

  if (parameter.component === "header") {
    const handleFile = async (file: File | undefined) => {
      if (!file) return
      setUploading(true)
      setUploadError(null)
      try {
        const asset = await uploadMediaAsset(file)
        setUploadedName(asset.name)
        onChange({ component: "header", source: "media_asset", media_asset_id: asset.id })
      } catch (error) {
        setUploadError(error instanceof Error ? error.message : t("common.error"))
      } finally {
        setUploading(false)
      }
    }

    const mode = parameter.source === "field" ? "field" : "upload"

    return (
      <div className="space-y-2 rounded-lg bg-muted/30 p-2">
        <div className="flex items-center gap-2">
          <span className="px-1 text-xs font-medium text-muted-foreground">{t("settings.automations.headerFile")}</span>
          <Select value={mode} onValueChange={(next) => onChange(next === "field" ? { component: "header", source: "field", path: "" } : { component: "header", source: "media_asset" })}>
            <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="upload">{t("settings.automations.documentUpload")}</SelectItem>
              <SelectItem value="field">{t("settings.automations.documentField")}</SelectItem>
            </SelectContent>
          </Select>
          <Button variant="ghost" size="icon" className="ml-auto" aria-label={t("settings.automations.removeParameter")} onClick={onRemove}><X className="size-4" /></Button>
        </div>
        {mode === "upload" ? (
          <div className="flex items-center gap-2">
            <Input type="file" accept="application/pdf" disabled={uploading} className="flex-1" onChange={(event) => void handleFile(event.target.files?.[0])} aria-label={t("settings.automations.documentUrl")} />
            <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{uploading ? t("settings.automations.uploading") : uploadError ? <span className="text-destructive">{uploadError}</span> : uploadedName ?? (parameter.media_asset_id ? t("settings.automations.fileLoaded") : t("settings.automations.noFile"))}</span>
          </div>
        ) : (
          <Select value={parameter.path ?? ""} onValueChange={(path) => onChange({ component: "header", source: "field", path })}>
            <SelectTrigger><SelectValue placeholder={t("settings.automations.selectField")} /></SelectTrigger>
            <SelectContent>
              {documentFieldOptions.length === 0 ? <div className="px-2 py-1.5 text-xs text-muted-foreground">{t("settings.automations.noDocumentFields")}</div> : documentFieldOptions.map((option) => <SelectItem key={option.path} value={option.path}>{option.label}{option.type === "url" ? ` · ${t("settings.automations.externalUrl")}` : ""}</SelectItem>)}
            </SelectContent>
          </Select>
        )}
      </div>
    )
  }

  return (
    <div className="grid gap-2 rounded-lg bg-muted/30 p-2 md:grid-cols-[120px_120px_1fr_1fr_auto]">
      <Input value={parameter.name ?? ""} onChange={(event) => onChange({ ...parameter, name: event.target.value })} placeholder="nombre" aria-label={t("settings.automations.parameter")} />
      <Select value={parameter.source} onValueChange={(source: "literal" | "field") => onChange({ ...parameter, source })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="field">{t("settings.automations.field")}</SelectItem><SelectItem value="literal">{t("settings.automations.literal")}</SelectItem></SelectContent></Select>
      <Input value={parameter.source === "literal" ? parameter.value ?? "" : parameter.path ?? ""} onChange={(event) => onChange({ ...parameter, ...(parameter.source === "literal" ? { value: event.target.value } : { path: event.target.value }) })} placeholder={parameter.source === "literal" ? t("settings.automations.value") : "contact.name"} aria-label={t("settings.automations.value")} />
      <Input value={parameter.fallback ?? ""} onChange={(event) => onChange({ ...parameter, fallback: event.target.value })} placeholder={t("settings.automations.fallback")} aria-label={t("settings.automations.fallback")} />
      <Button variant="ghost" size="icon" aria-label={t("settings.automations.removeParameter")} onClick={onRemove}><X className="size-4" /></Button>
    </div>
  )
}

function PreviewDialog({ rule, value, preview, onValueChange, onRun, onClose, t }: { rule: AutomationRule | null; value: { type: string; id: string }; preview: Record<string, unknown> | null; onValueChange: (value: { type: string; id: string }) => void; onRun: () => Promise<void>; onClose: () => void; t: (key: string) => string }) {
  return <Dialog open={Boolean(rule)} onOpenChange={(open) => { if (!open) onClose() }}><DialogContent className="max-w-2xl"><DialogHeader><DialogTitle>{t("settings.automations.preview")}: {rule?.name}</DialogTitle><DialogDescription>{t("settings.automations.previewHint")}</DialogDescription></DialogHeader><div className="grid gap-3 sm:grid-cols-[180px_1fr_auto]"><Select value={value.type} onValueChange={(type) => onValueChange({ ...value, type })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="contact">{t("settings.automations.contact")}</SelectItem><SelectItem value="conversation">{t("settings.automations.conversation")}</SelectItem></SelectContent></Select><Input type="number" min={1} value={value.id} onChange={(event) => onValueChange({ ...value, id: event.target.value })} placeholder="ID" /><Button onClick={() => void onRun()}>{t("settings.automations.evaluate")}</Button></div>{preview ? <pre className="max-h-80 overflow-auto rounded-xl border border-border bg-muted/40 p-4 text-xs leading-5">{JSON.stringify(preview, null, 2)}</pre> : null}</DialogContent></Dialog>
}

function RunsDialog({ rule, runs, onClose, onRetry, t }: { rule: AutomationRule | null; runs: AutomationRun[]; onClose: () => void; onRetry: (run: AutomationRun) => Promise<void>; t: (key: string) => string }) {
  return <Dialog open={Boolean(rule)} onOpenChange={(open) => { if (!open) onClose() }}><DialogContent className="max-h-[85vh] max-w-3xl overflow-y-auto"><DialogHeader><DialogTitle>{t("settings.automations.history")}: {rule?.name}</DialogTitle><DialogDescription>{t("settings.automations.historyHint")}</DialogDescription></DialogHeader><div className="divide-y divide-border rounded-xl border border-border">{runs.length === 0 ? <p className="p-8 text-center text-sm text-muted-foreground">{t("settings.automations.noRuns")}</p> : runs.map((run) => <div key={run.id} className="flex items-center gap-3 p-3"><RunIcon status={run.status} /><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><span className="text-sm font-medium">#{run.id}</span><StatusBadge status={run.status} label={t(`settings.automations.runStatus.${run.status}`)} /></div><p className="mt-1 truncate text-xs text-muted-foreground">{run.subject_type} #{run.subject_id} · {new Date(run.created_at).toLocaleString()}{run.error ? ` · ${run.error}` : ""}</p></div>{["failed", "skipped", "needs_review"].includes(run.status) ? <Button variant="outline" size="sm" onClick={() => void onRetry(run)}><RotateCcw className="mr-1.5 size-3.5" />{t("settings.automations.retry")}</Button> : null}</div>)}</div></DialogContent></Dialog>
}

function BuilderStep({ number, title, icon: Icon, optional, children }: { number: string; title: string; icon: typeof Workflow; optional?: string; children: React.ReactNode }) {
  return <section><div className="mb-4 flex items-center gap-3"><span className="flex size-8 items-center justify-center rounded-lg border border-border bg-muted text-sm font-semibold">{number}</span><Icon className="size-4 text-primary" /><h3 className="font-semibold">{title}</h3>{optional ? <Badge variant="outline" className="font-normal">{optional}</Badge> : null}</div><div className="ml-4 border-l border-border pl-7">{children}</div></section>
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="block space-y-2"><span className="block text-sm font-medium leading-none">{label}</span>{children}</label> }
function StatusBadge({ status, label }: { status: string; label: string }) { return <Badge variant="outline" className={cn("font-normal", status === "active" || status === "succeeded" ? "border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" : status === "failed" || status === "needs_review" ? "border-destructive/40 bg-destructive/10 text-destructive" : "bg-muted text-muted-foreground")}>{label}</Badge> }
function RunIcon({ status }: { status: AutomationRunStatus }) { if (status === "succeeded") return <CheckCircle2 className="size-5 text-emerald-500" />; if (status === "failed" || status === "needs_review") return <AlertTriangle className="size-5 text-destructive" />; if (status === "scheduled") return <CalendarClock className="size-5 text-sky-500" />; return <Clock3 className="size-5 text-muted-foreground" /> }
