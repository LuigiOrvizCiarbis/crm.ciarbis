"use client"

import { useEffect, useMemo, useState, type ReactNode } from "react"
import { ChevronDown, Clock, Loader2, MessageSquareText, TriangleAlert } from "lucide-react"

import { canUseFlowInTemplate } from "@/components/broadcasts/NewTemplateDialog"
import { useToast } from "@/components/Toast"
import { Button } from "@/components/ui/button"
import type { TemplateParameter, WhatsAppFlow, WhatsAppFlowSendTemplate, WhatsAppTemplate } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { ApiValidationError, updateFlow, type UpdateFlowPatch } from "@/lib/api/whatsapp-flows"
import {
  cleanParameters,
  parameterErrorsFrom,
  parametersComplete,
  sameParameters,
  templateBodyPreview,
  templateParameterRows,
  type ParameterContext,
} from "@/lib/template-parameters"
import { cn } from "@/lib/utils"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

import { ChatBubble, templateBubble } from "./ChatBubble"
import { FlowSendMessageFields, imageIncomplete, sendValuesFrom, toSendDefaults, type SendMessageValues } from "./FlowSendMessageFields"
import { OpeningTemplateRequest } from "./OpeningTemplateRequest"
import { matchesMessage, messageTemplateParameters, requestProblem } from "./openingTemplate"
import { TemplateParametersEditor } from "./TemplateParametersEditor"
import { TemplateStatusBadge, templateStatusKey } from "./TemplateStatusBadge"
import { useManagedTemplates } from "./useManagedTemplates"

interface FlowOpeningPanelProps {
  flow: WhatsAppFlow
  /** Canal del formulario: sin él no se pueden leer ni crear sus plantillas. */
  channelId: number | null
  onFlowChange: (flow: WhatsAppFlow) => void
  /** Avisa si el panel tiene cambios sin guardar. */
  onDirtyChange?: (dirty: boolean) => void
  /** Publica el formulario; sin él, sólo se avisa que hace falta publicarlo. */
  onPublish?: () => void
}

/** Edición pendiente: `defaultId` undefined = sin cambios; `parameters` sólo las plantillas tocadas. */
interface OpeningDraft {
  defaultId?: number
  parameters: Record<number, TemplateParameter[]>
}

const EMPTY_DRAFT: OpeningDraft = { parameters: {} }

/**
 * Apertura: un solo mensaje y cómo llega. Con la ventana de 24 h abierta se manda
 * tal cual; con la ventana cerrada, como plantilla aprobada por Meta con el mismo texto.
 */
export function FlowOpeningPanel({ flow, channelId, onFlowChange, onDirtyChange, onPublish }: FlowOpeningPanelProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canCreateTemplate = usePermission("templates.create")
  const contactFieldsStore = useContactFieldsStore()
  const { fetch: fetchContactFields } = contactFieldsStore
  useEffect(() => {
    void fetchContactFields()
  }, [fetchContactFields])
  const { templates, loading, failed, reload } = useManagedTemplates(channelId)
  const [values, setValues] = useState<SendMessageValues>(() => sendValuesFrom(flow.send_defaults))
  const [draft, setDraft] = useState<OpeningDraft>(EMPTY_DRAFT)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  // Las plantillas a las que les faltan variables arrancan abiertas.
  const [expanded, setExpanded] = useState<Set<number>>(
    () => new Set(flow.send_templates.filter((item) => item.issue === "parameters").map((item) => item.id)),
  )
  const [showOthers, setShowOthers] = useState(false)
  const [requesting, setRequesting] = useState(false)
  const [saving, setSaving] = useState(false)

  const context: ParameterContext = useMemo(
    () => ({ answers: [], contactFields: contactFieldsStore.fields }),
    [contactFieldsStore.fields],
  )
  const byId = useMemo(() => new Map((templates ?? []).map((template) => [template.id, template])), [templates])

  const message = toSendDefaults(values, context)
  const stored = flow.send_defaults
  const messageDirty =
    message.header !== stored.header ||
    JSON.stringify(message.image) !== JSON.stringify(stored.image) ||
    message.body !== stored.body ||
    message.footer !== stored.footer ||
    message.cta !== stored.cta ||
    !sameParameters(message.variables, stored.variables)
  const missingImage = imageIncomplete(values)
  const messageValid = requestProblem(message) !== "incomplete" && !missingImage
  // Lo que la plantilla que sigue al mensaje recibe en cada envío: la imagen y las variables del mensaje.
  const followedParameters = messageTemplateParameters(message)

  const linked = flow.send_templates
  const storedDefault = flow.opening.default_template_id
  const defaultId = draft.defaultId === undefined ? storedDefault : draft.defaultId
  // La que se ve primero: la elegida, si no la que se usa hoy, si no la primera.
  const primary =
    linked.find((item) => item.id === defaultId) ?? linked.find((item) => item.id === flow.effective_template_id) ?? linked[0] ?? null
  const others = linked.filter((item) => item.id !== primary?.id)
  const fallback = primary && !primary.ready ? others.find((item) => item.ready) ?? null : null
  const primaryTemplate = primary ? byId.get(primary.id) : undefined
  const outdated = primaryTemplate !== undefined && !matchesMessage(primaryTemplate, message)
  const usable = canUseFlowInTemplate(flow)
  const retired = flow.status === "DEPRECATED" || flow.status === "BLOCKED"

  // Una plantilla con el mismo texto que el mensaje usa su imagen y sus variables: se configuran una sola vez, en el mensaje.
  const followsMessage = (template: WhatsAppTemplate) => followedParameters.length > 0 && matchesMessage(template, message)
  const rowsFor = (template: WhatsAppTemplate) =>
    followsMessage(template)
      ? followedParameters
      : templateParameterRows(template, draft.parameters[template.id] ?? flow.opening.templates[String(template.id)]?.parameters ?? [], context)

  const editedIncomplete = Object.keys(draft.parameters).some((id) => {
    const template = byId.get(Number(id))
    return template ? !parametersComplete(rowsFor(template)) : false
  })
  const followingIds = linked.filter((item) => {
    const template = byId.get(item.id)
    return template !== undefined && followsMessage(template)
  }).map((item) => item.id)
  // Las variables sugeridas (p. ej. {{nombre}} → Nombre del contacto) de una plantilla que todavía
  // no tiene variables guardadas también se guardan, aunque no se hayan tocado.
  const suggestedIds = linked
    .filter((item) => item.issue === "parameters" && draft.parameters[item.id] === undefined && !followingIds.includes(item.id))
    .filter((item) => {
      const template = byId.get(item.id)
      return template !== undefined && parametersComplete(rowsFor(template))
    })
    .map((item) => item.id)
  const pendingIds = [...new Set([...Object.keys(draft.parameters).map(Number), ...suggestedIds, ...followingIds])]
  const openingDirty =
    draft.defaultId !== undefined ||
    Object.keys(draft.parameters).length > 0 ||
    suggestedIds.length > 0 ||
    linked.some((item) => followingIds.includes(item.id) && item.issue === "parameters")
  // Los textos sugeridos ya se usan sin guardarlos; guardarlos los fija aunque cambie el formulario.
  const canSave = (messageDirty || !flow.send_defaults_saved || openingDirty) && messageValid && !editedIncomplete && !saving
  // Sólo lo que tocó el usuario: las variables sugeridas no cuentan como cambios por perder.
  const panelDirty =
    messageDirty || (draft.defaultId !== undefined && draft.defaultId !== storedDefault) || Object.keys(draft.parameters).length > 0

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

  /**
   * Guarda mensaje y plantillas en un solo pedido. `requested` es una plantilla recién pedida a Meta:
   * queda como la de la apertura, con las variables del mensaje.
   */
  const persist = async (requested?: WhatsAppTemplate): Promise<boolean> => {
    const defaultChange = requested?.id ?? draft.defaultId
    const parameters: Record<string, { parameters: TemplateParameter[] }> = Object.fromEntries(
      pendingIds.flatMap((id) => {
        const template = byId.get(id)
        return template ? [[String(id), { parameters: cleanParameters(rowsFor(template)) }]] : []
      }),
    )
    if (requested && followedParameters.length > 0) parameters[String(requested.id)] = { parameters: followedParameters }
    const patch: UpdateFlowPatch = {}
    if (messageDirty || !flow.send_defaults_saved) patch.send_defaults = message
    if (defaultChange !== undefined || Object.keys(parameters).length > 0) {
      // El backend fusiona: sólo van las plantillas tocadas y la predeterminada si cambió.
      patch.opening = {
        ...(defaultChange !== undefined ? { default_template_id: defaultChange } : {}),
        templates: parameters,
      }
    }
    setSaving(true)
    try {
      const saved = await updateFlow(flow.id, patch)
      setDraft(EMPTY_DRAFT)
      setErrors({})
      onFlowChange(saved)
      return true
    } catch (error) {
      if (error instanceof ApiValidationError) {
        setErrors(error.errors)
        const failedIds = Object.keys(error.errors).flatMap((key) => {
          const match = /^opening\.templates\.(\d+)\./.exec(key)
          return match ? [Number(match[1])] : []
        })
        if (failedIds.length > 0) {
          setExpanded((current) => new Set([...current, ...failedIds]))
          if (failedIds.some((id) => id !== primary?.id)) setShowOthers(true)
        }
      }
      addToast({
        type: "error",
        title: t("flows.journey.opening.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
      return false
    } finally {
      setSaving(false)
    }
  }

  const save = async () => {
    if (!canSave) return
    if (await persist()) addToast({ type: "success", title: t("flows.journey.opening.saved") })
  }

  // La plantilla nueva queda como la de la apertura; hasta que Meta la apruebe se sigue usando la anterior.
  const handleRequested = async (created: WhatsAppTemplate) => {
    reload()
    setRequesting(false)
    if (await persist(created)) addToast({ type: "success", title: t("flows.journey.opening.request.sent") })
  }

  const otherErrors = Object.entries(errors)
    .filter(([key]) => !/^(opening\.templates\.\d+\.parameters|send_defaults\.(variables|image))/.test(key))
    .flatMap(([, messages]) => messages)
  const id = `flow-${flow.id}-opening`

  const requestForm = (onCancel?: () => void) =>
    channelId === null ? null : (
      <OpeningTemplateRequest
        flow={flow}
        channelId={channelId}
        message={message}
        incomplete={missingImage}
        templates={templates}
        onRequested={handleRequested}
        onCancel={onCancel}
      />
    )

  /** Variables y vista previa de una plantilla, plegadas. */
  const templateDetails = (item: WhatsAppFlowSendTemplate) => {
    const template = byId.get(item.id)
    if (!template) {
      return loading ? <p className="text-xs text-muted-foreground">{t("flows.journey.loadingTemplates")}</p> : null
    }
    const rows = rowsFor(template)
    const following = followsMessage(template)
    const rowErrors = parameterErrorsFrom(errors, `opening.templates.${item.id}.parameters`)
    const hasRowErrors = rowErrors.general.length > 0 || Object.keys(rowErrors.rows).length > 0
    const open = expanded.has(item.id) || hasRowErrors
    const sectionId = `${id}-template-${item.id}`
    return (
      <div className="space-y-2">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="-ml-2 h-8 px-2"
          aria-expanded={open}
          aria-controls={sectionId}
          onClick={() => toggleExpanded(item.id)}
        >
          {rows.length > 0 && !following ? t("flows.journey.opening.variables", { count: rows.length }) : t("flows.completion.preview")}
          <ChevronDown className={cn("size-4 transition-transform motion-reduce:transition-none", open && "rotate-180")} aria-hidden />
        </Button>
        {open ? (
          <div id={sectionId} className="space-y-3">
            {following ? (
              <p className="text-xs text-muted-foreground">{t("flows.journey.opening.usesMessageVariables")}</p>
            ) : rows.length > 0 ? (
              <TemplateParametersEditor
                template={template}
                parameters={rows}
                onChange={(next) => setParameters(item.id, next)}
                context={context}
                errors={rowErrors}
                idPrefix={`flow-${flow.id}-tpl-${item.id}`}
                disabled={saving}
              />
            ) : null}
            <div className="rounded-lg bg-[#efeae2] p-3 dark:bg-muted/40">
              <ChatBubble content={templateBubble(template, templateBodyPreview(template, rows, context, t))} />
            </div>
          </div>
        ) : null}
      </div>
    )
  }

  const meanwhile = fallback
    ? t("flows.journey.opening.meanwhileUses", { name: fallback.name })
    : t("flows.journey.opening.meanwhileNone")

  let closedNote: ReactNode
  let closedBody: ReactNode = null
  if (channelId === null) {
    closedNote = t("flows.journey.noChannel")
  } else if (primary) {
    const status = templateStatusKey(primary.status)
    const note = primary.ready
      ? t("flows.journey.opening.readyNote")
      : primary.issue === "parameters"
        ? t("flows.journey.opening.missingVariables")
        : status === "pending"
          ? `${t("flows.journey.opening.pendingNote")} ${meanwhile}`
          : status === "rejected"
            ? [
                t("flows.journey.opening.rejectedNote"),
                primaryTemplate?.rejected_reason ? t("flows.journey.opening.rejectedReason", { reason: primaryTemplate.rejected_reason }) : null,
                meanwhile,
              ].filter(Boolean).join(" ")
            : `${t("flows.journey.opening.unavailableNote")} ${meanwhile}`
    const canRequestAgain = canCreateTemplate && usable && (outdated || status === "rejected")
    closedNote = (
      <>
        <span className="flex flex-wrap items-center gap-1.5 pt-0.5">
          <span className="min-w-0 truncate text-xs font-medium text-foreground">
            {primary.name} <span className="font-normal text-muted-foreground">· {primary.language}</span>
          </span>
          <TemplateStatusBadge status={primary.status} missingParameters={primary.issue === "parameters"} />
        </span>
        <span className="block">{note}</span>
      </>
    )
    closedBody = (
      <>
        {outdated ? (
          <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
            <TriangleAlert className="mt-px size-3.5 shrink-0" aria-hidden />
            {t("flows.journey.opening.outdated")}
          </p>
        ) : null}
        {requesting ? (
          requestForm(() => setRequesting(false))
        ) : canRequestAgain ? (
          <Button type="button" size="sm" variant="outline" onClick={() => setRequesting(true)} disabled={saving}>
            {t("flows.journey.opening.requestAgain")}
          </Button>
        ) : null}
        {templateDetails(primary)}
      </>
    )
  } else if (retired) {
    closedNote = t("flows.journey.opening.retired")
  } else if (!usable) {
    closedNote = t("flows.journey.opening.publishFirst")
    closedBody = onPublish ? (
      <Button type="button" size="sm" variant="outline" onClick={onPublish}>
        {t("flows.journey.opening.publish")}
      </Button>
    ) : null
  } else if (!canCreateTemplate) {
    closedNote = t("flows.journey.opening.noPermission")
  } else {
    closedNote = t("flows.journey.opening.needsApproval")
    closedBody = requestForm()
  }

  return (
    <div className="space-y-6">
      <section className="space-y-3" aria-labelledby={`${id}-message`}>
        <div className="space-y-1">
          <h3 id={`${id}-message`} className="text-sm font-semibold text-foreground">
            {t("flows.journey.opening.messageTitle")}
          </h3>
          <p className="text-xs text-muted-foreground">{t("flows.journey.opening.messageHint")}</p>
        </div>
        <FlowSendMessageFields
          idPrefix={id}
          values={values}
          onChange={setValues}
          context={context}
          variableErrors={parameterErrorsFrom(errors, "send_defaults.variables")}
          imageErrors={Object.entries(errors).filter(([key]) => key.startsWith("send_defaults.image")).flatMap(([, messages]) => messages)}
          disabled={saving}
        />
      </section>

      <section className="space-y-3 border-t border-border pt-5" aria-labelledby={`${id}-delivery`}>
        <h3 id={`${id}-delivery`} className="text-sm font-semibold text-foreground">
          {t("flows.journey.opening.deliveryTitle")}
        </h3>
        <ul className="space-y-2">
          <li className="flex gap-3 rounded-md border border-border px-3 py-2.5">
            <MessageSquareText className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
            <div className="min-w-0 space-y-0.5">
              <p className="text-sm font-medium text-foreground">{t("flows.journey.opening.recentTitle")}</p>
              <p className="text-xs text-muted-foreground">{t("flows.journey.opening.recentNote")}</p>
            </div>
          </li>
          <li className="space-y-3 rounded-md border border-border px-3 py-2.5">
            <div className="flex gap-3">
              <Clock className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-0 flex-1 space-y-1">
                <p className="text-sm font-medium text-foreground">{t("flows.journey.opening.closedTitle")}</p>
                <div className="space-y-1 text-xs text-muted-foreground">{closedNote}</div>
              </div>
            </div>
            {closedBody ? <div className="space-y-3 pl-7">{closedBody}</div> : null}
          </li>
        </ul>

        {failed ? (
          <p className="text-xs text-destructive">
            {t("flows.journey.templatesLoadError")}{" "}
            <button type="button" className="underline underline-offset-2" onClick={reload}>
              {t("flows.journey.retry")}
            </button>
          </p>
        ) : null}

        {others.length > 0 ? (
          <div>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="-ml-2 h-8 px-2 text-muted-foreground"
              aria-expanded={showOthers}
              aria-controls={`${id}-others`}
              onClick={() => setShowOthers((current) => !current)}
            >
              {t("flows.journey.opening.othersTitle", { count: others.length })}
              <ChevronDown className={cn("size-4 transition-transform motion-reduce:transition-none", showOthers && "rotate-180")} aria-hidden />
            </Button>
            {showOthers ? (
              <ul id={`${id}-others`} className="mt-2 space-y-2">
                {others.map((item) => (
                  <li key={item.id} className="space-y-2 rounded-md border border-border px-3 py-2.5">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 space-y-1">
                        <p className="truncate text-sm font-medium text-foreground">
                          {item.name} <span className="font-normal text-muted-foreground">· {item.language}</span>
                        </p>
                        <TemplateStatusBadge status={item.status} missingParameters={item.issue === "parameters"} />
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8 shrink-0"
                        disabled={saving}
                        onClick={() => setDraft((current) => ({ ...current, defaultId: item.id }))}
                      >
                        {t("flows.journey.opening.useThis")}
                      </Button>
                    </div>
                    {templateDetails(item)}
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}

        {otherErrors.length > 0 ? <p className="text-xs text-destructive">{otherErrors.join(" ")}</p> : null}
      </section>

      <div className="flex items-center justify-end gap-3">
        {!flow.send_defaults_saved && !messageDirty ? (
          <p className="text-xs text-muted-foreground">{t("flows.journey.opening.suggestedMessage")}</p>
        ) : null}
        <Button type="button" size="sm" onClick={() => void save()} disabled={!canSave}>
          {saving ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : null}
          {t("common.save")}
        </Button>
      </div>
    </div>
  )
}
