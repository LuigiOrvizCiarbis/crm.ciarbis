"use client"

import { useState } from "react"
import { Loader2 } from "lucide-react"

import { BUTTON_TEXT_MAX, TEMPLATE_LANGUAGES } from "@/components/broadcasts/NewTemplateDialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import type { WhatsAppFlow, WhatsAppFlowSendDefaults, WhatsAppTemplate } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { createTemplate } from "@/lib/api/templates"

import { openingTemplateComponents, requestProblem, suggestTemplateName } from "./openingTemplate"

interface OpeningTemplateRequestProps {
  flow: WhatsAppFlow
  channelId: number
  /** El mensaje tal como está en pantalla: es el texto de la plantilla. */
  message: WhatsAppFlowSendDefaults
  /** Plantillas del canal, para no repetir nombre. */
  templates: WhatsAppTemplate[] | null
  /** Recibe la plantilla creada en Meta (pendiente de aprobación). */
  onRequested: (template: WhatsAppTemplate) => Promise<void>
  onCancel?: () => void
}

/** Pide a Meta aprobar el mensaje como plantilla: sólo nombre e idioma, el texto ya está escrito. */
export function OpeningTemplateRequest({ flow, channelId, message, templates, onRequested, onCancel }: OpeningTemplateRequestProps) {
  const { t } = useTranslation()
  const [language, setLanguage] = useState<string>(TEMPLATE_LANGUAGES[0].value)
  const [name, setName] = useState(() => suggestTemplateName(flow, templates, TEMPLATE_LANGUAGES[0].value))
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const problem = requestProblem(message)
  const nameError = !/^[a-z0-9_]+$/.test(name)
    ? t("flows.journey.opening.request.nameInvalid")
    : templates?.some((item) => item.name === name && item.language === language)
      ? t("flows.journey.opening.request.nameTaken")
      : null
  const id = `flow-${flow.id}-request`

  const submit = async () => {
    if (problem || nameError || submitting) return
    setSubmitting(true)
    setError(null)
    try {
      const created = await createTemplate(channelId, {
        name,
        language,
        // Meta define la categoría final al revisarla.
        category: "UTILITY",
        parameter_format: "named",
        components: openingTemplateComponents(flow, message),
      })
      await onRequested(created)
    } catch (requestError) {
      setError(requestError instanceof Error && requestError.message ? requestError.message : t("flows.journey.opening.request.error"))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-3">
      <div className="grid gap-3">
        <div className="space-y-1.5">
          <Label htmlFor={`${id}-name`}>{t("templateDialog.name")}</Label>
          <Input
            id={`${id}-name`}
            value={name}
            disabled={submitting}
            aria-invalid={nameError !== null}
            aria-describedby={nameError ? `${id}-name-error` : undefined}
            onChange={(event) => setName(event.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_"))}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`${id}-language`}>{t("templateDialog.language")}</Label>
          <Select value={language} onValueChange={setLanguage} disabled={submitting}>
            <SelectTrigger id={`${id}-language`} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TEMPLATE_LANGUAGES.map((item) => (
                <SelectItem key={item.value} value={item.value}>{item.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
      {nameError ? <p id={`${id}-name-error`} className="text-xs text-destructive">{nameError}</p> : null}
      {problem ? (
        <p className="text-xs text-amber-700 dark:text-amber-400">
          {t(`flows.journey.opening.request.problems.${problem}`, { max: BUTTON_TEXT_MAX })}
        </p>
      ) : null}
      {error ? <p role="alert" className="text-xs text-destructive">{error}</p> : null}
      <div className="flex justify-end gap-2">
        {onCancel ? (
          <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={submitting}>
            {t("common.cancel")}
          </Button>
        ) : null}
        <Button type="button" size="sm" onClick={() => void submit()} disabled={problem !== null || nameError !== null || submitting}>
          {submitting ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : null}
          {t("flows.journey.opening.request.submit")}
        </Button>
      </div>
    </div>
  )
}
