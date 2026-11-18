"use client"

import { useEffect, useMemo, useState } from "react"
import { Loader2, MessageSquarePlus, TriangleAlert } from "lucide-react"

import { NewTemplateDialog, closingTemplateDraft, type TemplateDraft } from "@/components/broadcasts/NewTemplateDialog"
import { FlowCompletionMessageForm } from "@/components/flows/FlowCompletionMessageForm"
import { useToast } from "@/components/Toast"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import type { TemplateParameter, WhatsAppFlow, WhatsAppFlowResponseField, WhatsAppTemplate } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { ApiValidationError, updateFlow } from "@/lib/api/whatsapp-flows"
import {
  cleanParameters,
  parameterErrorsFrom,
  parametersComplete,
  templateBodyPreview,
  templateParameterRows,
  type ParameterContext,
} from "@/lib/template-parameters"
import { hasFlowButton } from "@/lib/whatsapp-templates"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

import { ChatBubble, templateBubble } from "./ChatBubble"
import { TemplateParametersEditor } from "./TemplateParametersEditor"
import { TemplateStatusBadge, templateStatusKey } from "./TemplateStatusBadge"
import { useManagedTemplates } from "./useManagedTemplates"

type ClosingMode = "none" | "text" | "template"

/** Plantillas que ya no existen en Meta: no se ofrecen. */
const GONE_STATUSES: Array<WhatsAppTemplate["status"]> = ["DELETED", "PENDING_DELETION"]

interface FlowClosingPanelProps {
  flow: WhatsAppFlow
  channelId: number | null
  /** Respuestas de la versión publicada que se pueden usar en el mensaje o en las variables (vacío en formularios JSON). */
  fields: WhatsAppFlowResponseField[]
  /** El borrador tiene preguntas que todavía no están publicadas: no se pueden citar hasta publicar. */
  hasUnpublishedFields?: boolean
  onFlowChange: (flow: WhatsAppFlow) => void
  /** Avisa si el panel tiene cambios sin guardar respecto del cierre guardado. */
  onDirtyChange?: (dirty: boolean) => void
}

/** Cierre: lo que el CRM manda en el chat cuando llega la respuesta (nada, un mensaje o una plantilla). */
export function FlowClosingPanel({ flow, channelId, fields, hasUnpublishedFields = false, onFlowChange, onDirtyChange }: FlowClosingPanelProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const stored = flow.completion_message
  const storedMode: ClosingMode = stored?.type ?? "none"
  const [mode, setMode] = useState<ClosingMode>(storedMode)
  const [clearing, setClearing] = useState(false)
  const [formDirty, setFormDirty] = useState(false)
  const dirty = mode !== storedMode || (mode !== "none" && formDirty)

  useEffect(() => {
    if (!onDirtyChange) return
    onDirtyChange(dirty)
    return () => onDirtyChange(false)
  }, [dirty, onDirtyChange])

  const clear = async () => {
    setClearing(true)
    try {
      onFlowChange(await updateFlow(flow.id, { completion_message: null }))
      addToast({ type: "success", title: t("flows.journey.closing.saved") })
    } catch (error) {
      addToast({
        type: "error",
        title: t("flows.journey.closing.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setClearing(false)
    }
  }

  return (
    <div className="space-y-4">
      <ToggleGroup
        type="single"
        variant="outline"
        value={mode}
        onValueChange={(next) => next && setMode(next as ClosingMode)}
        aria-label={t("flows.journey.closing.modeLabel")}
        className="w-full"
      >
        {(["none", "text", "template"] as const).map((item) => (
          <ToggleGroupItem key={item} value={item} className="text-xs">
            {t(`flows.journey.closing.modes.${item}`)}
          </ToggleGroupItem>
        ))}
      </ToggleGroup>

      {mode !== "none" && hasUnpublishedFields ? (
        <p className="text-xs text-muted-foreground">{t("flows.journey.closing.unpublishedFields")}</p>
      ) : null}

      {mode === "none" ? (
        <div className="space-y-3">
          <p className="text-xs text-muted-foreground">{t("flows.journey.closing.noneHint")}</p>
          {stored !== null ? (
            <div className="flex justify-end">
              <Button type="button" size="sm" onClick={() => void clear()} disabled={clearing}>
                {clearing ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : null}
                {t("common.save")}
              </Button>
            </div>
          ) : null}
        </div>
      ) : mode === "text" ? (
        <FlowCompletionMessageForm key={flow.id} flow={flow} fields={fields} onSaved={onFlowChange} onDirtyChange={setFormDirty} />
      ) : (
        <ClosingTemplateForm
          key={flow.id}
          flow={flow}
          channelId={channelId}
          fields={fields}
          onFlowChange={onFlowChange}
          onDirtyChange={setFormDirty}
        />
      )}
    </div>
  )
}

interface ClosingTemplateFormProps {
  flow: WhatsAppFlow
  channelId: number | null
  fields: WhatsAppFlowResponseField[]
  onFlowChange: (flow: WhatsAppFlow) => void
  onDirtyChange: (dirty: boolean) => void
}

function ClosingTemplateForm({ flow, channelId, fields, onFlowChange, onDirtyChange }: ClosingTemplateFormProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canCreateTemplate = usePermission("templates.create")
  const contactFieldsStore = useContactFieldsStore()
  const { fetch: fetchContactFields } = contactFieldsStore
  useEffect(() => {
    void fetchContactFields()
  }, [fetchContactFields])
  const { templates, loading, failed, reload } = useManagedTemplates(channelId)

  const stored = flow.completion_message?.type === "template" ? flow.completion_message : null
  const [templateId, setTemplateId] = useState<number | null>(stored?.template_id ?? null)
  const [edited, setEdited] = useState<TemplateParameter[] | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [saving, setSaving] = useState(false)
  const [templateDraft, setTemplateDraft] = useState<{ key: string; draft: TemplateDraft } | null>(null)

  const context: ParameterContext = useMemo(
    () => ({ answers: fields, contactFields: contactFieldsStore.fields }),
    [fields, contactFieldsStore.fields],
  )
  // Una plantilla con botón FLOW abriría otro formulario en cada respuesta: no sirve como cierre.
  const options = (templates ?? []).filter(
    (template) => !GONE_STATUSES.includes(template.status) && !hasFlowButton(template.components),
  )
  const template = options.find((item) => item.id === templateId)
  const opensForm =
    templateId !== null && !template
      ? (templates ?? []).find((item) => item.id === templateId && hasFlowButton(item.components))
      : undefined
  const rows = template
    ? templateParameterRows(template, edited ?? (stored?.template_id === template.id ? stored.parameters : []), context)
    : []
  const complete = parametersComplete(rows)
  const canSave = template !== undefined && complete && !saving
  const dirty = templateId !== (stored?.template_id ?? null) || edited !== null

  useEffect(() => {
    onDirtyChange(dirty)
    return () => onDirtyChange(false)
  }, [dirty, onDirtyChange])

  const choose = (id: number) => {
    setTemplateId(id)
    setEdited(null)
    setErrors({})
  }

  const save = async () => {
    if (!canSave || !template) return
    setSaving(true)
    try {
      const saved = await updateFlow(flow.id, {
        completion_message: { type: "template", template_id: template.id, parameters: cleanParameters(rows) },
      })
      setEdited(null)
      setErrors({})
      onFlowChange(saved)
      addToast({ type: "success", title: t("flows.journey.closing.saved") })
    } catch (error) {
      if (error instanceof ApiValidationError) setErrors(error.errors)
      addToast({
        type: "error",
        title: t("flows.journey.closing.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setSaving(false)
    }
  }

  const templateErrors = errors["completion_message.template_id"] ?? []
  const otherErrors = Object.entries(errors)
    .filter(([key]) => key !== "completion_message.template_id" && !key.startsWith("completion_message.parameters"))
    .flatMap(([, messages]) => messages)
  const storedText = flow.completion_message?.type === "text" ? flow.completion_message : null

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted-foreground">{t("flows.journey.closing.templateHint")}</p>

      {channelId === null ? <p className="text-xs text-muted-foreground">{t("flows.journey.noChannel")}</p> : null}
      {failed ? (
        <p className="text-xs text-destructive">
          {t("flows.journey.templatesLoadError")}{" "}
          <button type="button" className="underline underline-offset-2" onClick={reload}>
            {t("flows.journey.retry")}
          </button>
        </p>
      ) : null}

      <div className="space-y-1.5">
        <Label htmlFor={`flow-${flow.id}-closing-template`}>{t("flows.journey.closing.template")}</Label>
        <Select
          value={templateId !== null ? String(templateId) : ""}
          onValueChange={(value) => choose(Number(value))}
          disabled={loading || channelId === null}
        >
          <SelectTrigger id={`flow-${flow.id}-closing-template`} className="w-full" aria-invalid={templateErrors.length > 0}>
            <SelectValue placeholder={loading ? t("flows.journey.loadingTemplates") : t("flows.journey.closing.selectTemplate")} />
          </SelectTrigger>
          <SelectContent>
            {options.map((item) => (
              <SelectItem key={item.id} value={String(item.id)}>
                {item.name} · {item.language} · {t(`flows.journey.templateStatus.${templateStatusKey(item.status)}`)}
              </SelectItem>
            ))}
            {opensForm ? (
              <SelectItem value={String(opensForm.id)} disabled>
                {opensForm.name} · {opensForm.language} · {t("flows.journey.closing.opensFormLabel")}
              </SelectItem>
            ) : templateId !== null && templates !== null && !template ? (
              <SelectItem value={String(templateId)}>{t("flows.journey.closing.missingTemplate", { id: templateId })}</SelectItem>
            ) : null}
          </SelectContent>
        </Select>
        {templates !== null && options.length === 0 ? (
          <p className="text-xs text-muted-foreground">{t("flows.journey.closing.noTemplates")}</p>
        ) : null}
        {templateErrors.length > 0 ? <p className="text-xs text-destructive">{templateErrors.join(" ")}</p> : null}
        {opensForm ? (
          <p className="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
            <TriangleAlert className="size-3.5 shrink-0" aria-hidden />
            {t("flows.journey.closing.opensForm")}
          </p>
        ) : null}
        {template ? (
          <div className="flex flex-wrap items-center gap-2">
            <TemplateStatusBadge status={template.status} />
            {template.status !== "APPROVED" ? (
              <span className="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                <TriangleAlert className="size-3.5 shrink-0" aria-hidden />
                {t("flows.journey.closing.notApproved")}
              </span>
            ) : null}
          </div>
        ) : null}
      </div>

      {template ? (
        <>
          <div className="space-y-2">
            <p className="text-sm font-medium text-foreground">{t("flows.journey.closing.variables")}</p>
            <TemplateParametersEditor
              template={template}
              parameters={rows}
              onChange={(next) => {
                setEdited(next)
                setErrors((current) =>
                  Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith("completion_message.parameters"))),
                )
              }}
              context={context}
              errors={parameterErrorsFrom(errors, "completion_message.parameters")}
              idPrefix={`flow-${flow.id}-closing`}
              disabled={saving}
            />
          </div>
          <div className="space-y-1.5">
            <p className="text-xs font-medium text-muted-foreground">{t("flows.completion.preview")}</p>
            <div className="rounded-lg bg-[#efeae2] p-3 dark:bg-muted/40">
              <ChatBubble content={templateBubble(template, templateBodyPreview(template, rows, context, t))} />
            </div>
          </div>
        </>
      ) : null}

      {otherErrors.length > 0 ? <p className="text-xs text-destructive">{otherErrors.join(" ")}</p> : null}

      <div className="flex flex-wrap items-center justify-between gap-2">
        {canCreateTemplate ? (
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={channelId === null}
            onClick={() => setTemplateDraft({ key: `${flow.id}-${Date.now()}`, draft: closingTemplateDraft(flow, storedText) })}
          >
            <MessageSquarePlus className="size-4" />
            {t("flows.journey.closing.createTemplate")}
          </Button>
        ) : (
          <span />
        )}
        <Button type="button" size="sm" onClick={() => void save()} disabled={!canSave}>
          {saving ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : null}
          {t("common.save")}
        </Button>
      </div>

      {templateDraft && channelId !== null ? (
        <NewTemplateDialog
          key={templateDraft.key}
          open
          onOpenChange={(open) => !open && setTemplateDraft(null)}
          channelId={channelId}
          draft={templateDraft.draft}
          onCreated={async (created) => {
            reload()
            choose(created.id)
          }}
        />
      ) : null}
    </div>
  )
}
