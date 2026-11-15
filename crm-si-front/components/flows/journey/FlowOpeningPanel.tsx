"use client"

import { useEffect, useMemo, useState } from "react"
import { ChevronDown, Loader2, MessageSquarePlus } from "lucide-react"

import { NewTemplateDialog, canUseFlowInTemplate, flowTemplateDraft, type TemplateDraft } from "@/components/broadcasts/NewTemplateDialog"
import { useToast } from "@/components/Toast"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import type { TemplateParameter, WhatsAppFlow, WhatsAppFlowOpening, WhatsAppTemplate } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { ApiValidationError, getFlow, updateFlow } from "@/lib/api/whatsapp-flows"
import {
  cleanParameters,
  parameterErrorsFrom,
  parametersComplete,
  templateParameterRows,
  type ParameterContext,
} from "@/lib/template-parameters"
import { cn } from "@/lib/utils"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

import { FlowSendMessageForm } from "./FlowSendMessageForm"
import { TemplateParametersEditor } from "./TemplateParametersEditor"
import { TemplateStatusBadge } from "./TemplateStatusBadge"
import { useManagedTemplates } from "./useManagedTemplates"

const AUTOMATIC = "auto"

interface FlowOpeningPanelProps {
  flow: WhatsAppFlow
  /** Canal del formulario: sin él no se pueden leer ni crear sus plantillas. */
  channelId: number | null
  onFlowChange: (flow: WhatsAppFlow) => void
  /** Avisa si el panel tiene cambios sin guardar (mensaje o plantillas). */
  onDirtyChange?: (dirty: boolean) => void
}

/** Edición pendiente: `defaultId` undefined = sin cambios; `parameters` sólo las plantillas tocadas. */
interface OpeningDraft {
  defaultId?: number | null
  parameters: Record<number, TemplateParameter[]>
}

const EMPTY_DRAFT: OpeningDraft = { parameters: {} }

/** Apertura: cómo le llega el formulario al cliente, con la ventana abierta (mensaje) o cerrada (plantilla). */
export function FlowOpeningPanel({ flow, channelId, onFlowChange, onDirtyChange }: FlowOpeningPanelProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canCreateTemplate = usePermission("templates.create")
  const contactFieldsStore = useContactFieldsStore()
  const { fetch: fetchContactFields } = contactFieldsStore
  useEffect(() => {
    void fetchContactFields()
  }, [fetchContactFields])
  const { templates, loading, failed, reload } = useManagedTemplates(channelId)
  const [draft, setDraft] = useState<OpeningDraft>(EMPTY_DRAFT)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [expanded, setExpanded] = useState<Set<number>>(() => new Set())
  const [saving, setSaving] = useState(false)
  const [templateDraft, setTemplateDraft] = useState<{ key: string; draft: TemplateDraft } | null>(null)
  const [sendDirty, setSendDirty] = useState(false)

  const context: ParameterContext = useMemo(
    () => ({ answers: [], contactFields: contactFieldsStore.fields }),
    [contactFieldsStore.fields],
  )
  const byId = useMemo(() => new Map((templates ?? []).map((template) => [template.id, template])), [templates])

  const storedDefault = flow.opening.default_template_id
  const defaultId = draft.defaultId === undefined ? storedDefault : draft.defaultId
  const effective = flow.send_templates.find((item) => item.id === flow.effective_template_id) ?? null
  const chosen = defaultId === null ? null : flow.send_templates.find((item) => item.id === defaultId)

  const rowsFor = (template: WhatsAppTemplate) =>
    templateParameterRows(template, draft.parameters[template.id] ?? flow.opening.templates[String(template.id)]?.parameters ?? [], context)

  const editedIncomplete = Object.keys(draft.parameters).some((id) => {
    const template = byId.get(Number(id))
    return template ? !parametersComplete(rowsFor(template)) : false
  })
  // Las variables sugeridas (p. ej. {{nombre}} → Nombre del contacto) de una plantilla que todavía
  // no tiene variables guardadas también se guardan, aunque no se hayan tocado.
  const suggestedIds = flow.send_templates
    .filter((item) => item.issue === "parameters" && draft.parameters[item.id] === undefined)
    .filter((item) => {
      const template = byId.get(item.id)
      return template !== undefined && parametersComplete(rowsFor(template))
    })
    .map((item) => item.id)
  const pendingIds = [...Object.keys(draft.parameters).map(Number), ...suggestedIds]
  const dirty = draft.defaultId !== undefined || pendingIds.length > 0
  const canSave = dirty && !saving && !editedIncomplete
  // Sólo lo que tocó el usuario: las variables sugeridas no cuentan como cambios por perder.
  const panelDirty =
    sendDirty || (draft.defaultId !== undefined && draft.defaultId !== storedDefault) || Object.keys(draft.parameters).length > 0

  useEffect(() => {
    if (!onDirtyChange) return
    onDirtyChange(panelDirty)
    return () => onDirtyChange(false)
  }, [panelDirty, onDirtyChange])

  const setParameters = (id: number, parameters: TemplateParameter[]) => {
    setDraft((current) => ({ ...current, parameters: { ...current.parameters, [id]: parameters } }))
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith(`opening.templates.${id}.`))))
  }

  const toggleExpanded = (id: number) =>
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const save = async () => {
    if (!canSave) return
    // El backend fusiona: sólo van las plantillas tocadas y la predeterminada si cambió.
    const opening: Partial<WhatsAppFlowOpening> = {
      ...(draft.defaultId !== undefined ? { default_template_id: draft.defaultId } : {}),
      templates: Object.fromEntries(
        pendingIds.flatMap((id) => {
          const template = byId.get(id)
          return template ? [[String(id), { parameters: cleanParameters(rowsFor(template)) }]] : []
        }),
      ),
    }
    setSaving(true)
    try {
      const saved = await updateFlow(flow.id, { opening })
      setDraft(EMPTY_DRAFT)
      setErrors({})
      onFlowChange(saved)
      addToast({ type: "success", title: t("flows.journey.opening.saved") })
    } catch (error) {
      if (error instanceof ApiValidationError) {
        setErrors(error.errors)
        setExpanded((current) => {
          const next = new Set(current)
          for (const key of Object.keys(error.errors)) {
            const match = /^opening\.templates\.(\d+)\./.exec(key)
            if (match) next.add(Number(match[1]))
          }
          return next
        })
      }
      addToast({
        type: "error",
        title: t("flows.journey.opening.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setSaving(false)
    }
  }

  const refreshFlow = async () => {
    reload()
    try {
      onFlowChange(await getFlow(flow.id))
    } catch (error) {
      addToast({
        type: "error",
        title: t("flows.journey.opening.refreshError"),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }

  const otherErrors = Object.entries(errors)
    .filter(([key]) => !/^opening\.templates\.\d+\.parameters/.test(key))
    .flatMap(([, messages]) => messages)
  const usable = canUseFlowInTemplate(flow)
  const retired = flow.status === "DEPRECATED" || flow.status === "BLOCKED"

  return (
    <div className="space-y-6">
      <section className="space-y-3" aria-labelledby={`flow-${flow.id}-opening-message`}>
        <div className="space-y-1">
          <h3 id={`flow-${flow.id}-opening-message`} className="text-sm font-semibold text-foreground">
            {t("flows.journey.opening.messageTitle")}
          </h3>
          <p className="text-xs text-muted-foreground">{t("flows.journey.opening.messageHint")}</p>
        </div>
        <FlowSendMessageForm key={flow.id} flow={flow} onSaved={onFlowChange} onDirtyChange={setSendDirty} />
      </section>

      <section className="space-y-3 border-t border-border pt-5" aria-labelledby={`flow-${flow.id}-opening-templates`}>
        <div className="space-y-1">
          <h3 id={`flow-${flow.id}-opening-templates`} className="text-sm font-semibold text-foreground">
            {t("flows.journey.opening.templatesTitle")}
          </h3>
          <p className="text-xs text-muted-foreground">{t("flows.journey.opening.templatesHint")}</p>
        </div>

        {channelId === null ? <p className="text-xs text-muted-foreground">{t("flows.journey.noChannel")}</p> : null}
        {failed ? (
          <p className="text-xs text-destructive">
            {t("flows.journey.templatesLoadError")}{" "}
            <button type="button" className="underline underline-offset-2" onClick={reload}>
              {t("flows.journey.retry")}
            </button>
          </p>
        ) : null}

        {flow.send_templates.length === 0 ? (
          <p className="rounded-md border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
            {t("flows.journey.opening.noTemplates")}
          </p>
        ) : (
          <RadioGroup
            value={defaultId === null ? AUTOMATIC : String(defaultId)}
            onValueChange={(value) => setDraft((current) => ({ ...current, defaultId: value === AUTOMATIC ? null : Number(value) }))}
            aria-label={t("flows.journey.opening.defaultLabel")}
            className="gap-2"
          >
            <label
              htmlFor={`flow-${flow.id}-default-auto`}
              className={cn(
                "flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5",
                defaultId === null ? "border-primary" : "border-border",
              )}
            >
              <RadioGroupItem id={`flow-${flow.id}-default-auto`} value={AUTOMATIC} className="mt-0.5" />
              <span className="min-w-0 space-y-0.5">
                <span className="block text-sm font-medium text-foreground">{t("flows.journey.opening.automatic")}</span>
                <span className="block text-xs text-muted-foreground">
                  {effective
                    ? t("flows.journey.opening.automaticUses", { name: effective.name })
                    : t("flows.journey.opening.noneReady")}
                </span>
              </span>
            </label>

            {flow.send_templates.map((item) => {
              const template = byId.get(item.id)
              const rows = template ? rowsFor(template) : []
              const open = expanded.has(item.id)
              const rowErrors = parameterErrorsFrom(errors, `opening.templates.${item.id}.parameters`)
              const hasRowErrors = rowErrors.general.length > 0 || Object.keys(rowErrors.rows).length > 0
              return (
                <div
                  key={item.id}
                  className={cn("rounded-md border", defaultId === item.id ? "border-primary" : "border-border")}
                >
                  <div className="flex items-start gap-3 px-3 py-2.5">
                    <RadioGroupItem id={`flow-${flow.id}-default-${item.id}`} value={String(item.id)} className="mt-0.5" />
                    <div className="min-w-0 flex-1 space-y-1">
                      <Label htmlFor={`flow-${flow.id}-default-${item.id}`} className="block cursor-pointer truncate text-sm font-medium">
                        {item.name} <span className="font-normal text-muted-foreground">· {item.language}</span>
                      </Label>
                      <div className="flex flex-wrap items-center gap-1.5">
                        <TemplateStatusBadge status={item.status} missingParameters={item.issue === "parameters"} />
                        {flow.effective_template_id === item.id ? (
                          <Badge variant="secondary" className="font-normal">{t("flows.journey.opening.inUse")}</Badge>
                        ) : null}
                      </div>
                    </div>
                    {template && rows.length > 0 ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 shrink-0"
                        aria-expanded={open}
                        aria-controls={`flow-${flow.id}-params-${item.id}`}
                        onClick={() => toggleExpanded(item.id)}
                      >
                        {t("flows.journey.opening.variables", { count: rows.length })}
                        <ChevronDown className={cn("size-4 transition-transform", open && "rotate-180")} aria-hidden />
                      </Button>
                    ) : null}
                  </div>
                  {template && rows.length > 0 && (open || hasRowErrors) ? (
                    <div id={`flow-${flow.id}-params-${item.id}`} className="border-t border-border px-3 py-3">
                      <TemplateParametersEditor
                        template={template}
                        parameters={rows}
                        onChange={(next) => setParameters(item.id, next)}
                        context={context}
                        errors={rowErrors}
                        idPrefix={`flow-${flow.id}-tpl-${item.id}`}
                        disabled={saving}
                      />
                    </div>
                  ) : null}
                  {loading && !template ? (
                    <p className="border-t border-border px-3 py-2 text-xs text-muted-foreground">{t("flows.journey.loadingTemplates")}</p>
                  ) : null}
                </div>
              )
            })}
          </RadioGroup>
        )}

        {chosen && !chosen.ready ? (
          <p className="text-xs text-amber-700 dark:text-amber-400">{t("flows.journey.opening.chosenNotReady")}</p>
        ) : null}
        {otherErrors.length > 0 ? <p className="text-xs text-destructive">{otherErrors.join(" ")}</p> : null}

        <div className="flex flex-wrap items-center justify-between gap-2">
          {canCreateTemplate ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={!usable || channelId === null}
              onClick={() => setTemplateDraft({ key: `${flow.id}-${Date.now()}`, draft: flowTemplateDraft(flow) })}
            >
              <MessageSquarePlus className="size-4" />
              {t("flows.journey.opening.createTemplate")}
            </Button>
          ) : (
            <span />
          )}
          {flow.send_templates.length > 0 ? (
            <Button type="button" size="sm" onClick={() => void save()} disabled={!canSave}>
              {saving ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : null}
              {t("flows.journey.opening.saveTemplates")}
            </Button>
          ) : null}
        </div>
        {canCreateTemplate && !usable ? (
          <p className="text-xs text-muted-foreground">
            {retired ? t("flows.journey.opening.retired") : t("flows.journey.opening.publishFirst")}
          </p>
        ) : null}
      </section>

      {templateDraft && channelId !== null ? (
        <NewTemplateDialog
          key={templateDraft.key}
          open
          onOpenChange={(open) => !open && setTemplateDraft(null)}
          channelId={channelId}
          draft={templateDraft.draft}
          onCreated={refreshFlow}
        />
      ) : null}
    </div>
  )
}
