"use client"

import { useEffect, useState } from "react"
import { AlertTriangle, Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import type { WhatsAppFlow, WhatsAppFlowCategory, WhatsAppFlowValidationError } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import {
  ApiValidationError,
  createFlow,
  getFlow,
  updateFlow,
  type UpdateFlowPatch,
} from "@/lib/api/whatsapp-flows"
import { FLOW_CATEGORIES } from "./flow-labels"

interface FlowJsonDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
  /** null para importar un formulario nuevo. */
  flowId: number | null
  onSaved: (flow: WhatsAppFlow) => void
}

const NAME_MAX = 200

export function FlowJsonDialog({ open, onOpenChange, channelId, flowId, onSaved }: FlowJsonDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [original, setOriginal] = useState<WhatsAppFlow | null>(null)
  const [name, setName] = useState("")
  const [categories, setCategories] = useState<WhatsAppFlowCategory[]>(["OTHER"])
  const [json, setJson] = useState("")
  const [jsonError, setJsonError] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<string[]>([])
  const [validationErrors, setValidationErrors] = useState<WhatsAppFlowValidationError[]>([])

  // Cada apertura arranca limpia; en edición se trae el detalle con el JSON.
  useEffect(() => {
    if (!open) return
    setJsonError(null)
    setServerErrors([])
    setValidationErrors([])
    setOriginal(null)
    if (flowId === null) {
      setName("")
      setCategories(["OTHER"])
      setJson("")
      return
    }
    let cancelled = false
    setLoading(true)
    getFlow(flowId)
      .then((flow) => {
        if (cancelled) return
        setOriginal(flow)
        setName(flow.name)
        setCategories(flow.categories.length > 0 ? flow.categories : ["OTHER"])
        setJson(prettyJson(flow.flow_json ?? ""))
        setValidationErrors(flow.validation_errors ?? [])
      })
      .catch((error) => {
        if (cancelled) return
        addToast({
          type: "error",
          title: t("flows.settings.json.loadError"),
          description: error instanceof Error ? error.message : undefined,
        })
        onOpenChange(false)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, flowId])

  const toggleCategory = (category: WhatsAppFlowCategory, checked: boolean) => {
    setCategories((current) =>
      checked ? [...current, category] : current.filter((c) => c !== category),
    )
  }

  const isEdit = flowId !== null
  const originalJson = prettyJson(original?.flow_json ?? "")
  const jsonChanged = !isEdit || json !== originalJson
  const canSave =
    !loading &&
    !saving &&
    name.trim().length > 0 &&
    categories.length > 0 &&
    (isEdit || json.trim().length > 0)

  const save = async () => {
    setServerErrors([])
    let compact: string | null = null
    if (jsonChanged) {
      try {
        // Meta recibe el JSON como string: se valida y se compacta acá.
        compact = JSON.stringify(JSON.parse(json))
        setJsonError(null)
      } catch (error) {
        setJsonError(
          t("flows.settings.json.invalidJson", {
            detail: error instanceof Error ? error.message : "",
          }),
        )
        return
      }
    }

    setSaving(true)
    try {
      let saved: WhatsAppFlow
      if (!isEdit) {
        saved = await createFlow(channelId, {
          name: name.trim(),
          categories,
          flow_json: compact as string,
        })
      } else {
        const patch: UpdateFlowPatch = {}
        if (name.trim() !== original?.name) patch.name = name.trim()
        if (
          categories.length !== (original?.categories.length ?? 0) ||
          categories.some((category) => !original?.categories.includes(category))
        ) {
          patch.categories = categories
        }
        if (compact !== null) patch.flow_json = compact
        if (Object.keys(patch).length === 0) {
          onOpenChange(false)
          return
        }
        saved = await updateFlow(flowId as number, patch)
      }

      onSaved(saved)
      const errors = saved.validation_errors ?? []
      setValidationErrors(errors)
      if (errors.length > 0) {
        // Queda abierto para corregir. Al importar, el padre pasa el id nuevo
        // y el diálogo recarga el formulario guardado en modo edición.
        setOriginal({ ...saved, flow_json: compact ?? original?.flow_json ?? null })
        addToast({
          type: "warning",
          title: t("flows.settings.json.savedWithErrors", { count: errors.length }),
        })
        return
      }
      addToast({ type: "success", title: t("flows.settings.json.saved") })
      onOpenChange(false)
    } catch (error) {
      if (error instanceof ApiValidationError) {
        setServerErrors(Object.values(error.errors).flat())
      }
      addToast({
        type: "error",
        title: t("flows.settings.json.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("flows.settings.json.editTitle") : t("flows.settings.json.importTitle")}
          </DialogTitle>
          <DialogDescription>{t("flows.settings.json.description")}</DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="flex items-center justify-center py-12 text-muted-foreground">
            <Loader2 className="size-5 animate-spin motion-reduce:animate-none" />
          </div>
        ) : (
          <div className="space-y-5">
            {original?.has_builder_definition && (
              <div className="flex gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                <p>{t("flows.settings.json.builderWarning")}</p>
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="flow-json-name">{t("flows.settings.json.name")}</Label>
              <Input
                id="flow-json-name"
                value={name}
                maxLength={NAME_MAX}
                onChange={(event) => setName(event.target.value)}
              />
            </div>

            <fieldset className="space-y-2">
              <legend className="text-sm font-medium">{t("flows.settings.json.categories")}</legend>
              <div className="grid gap-2 sm:grid-cols-2">
                {FLOW_CATEGORIES.map((category) => (
                  <label key={category} className="flex items-center gap-2 text-sm">
                    <Checkbox
                      checked={categories.includes(category)}
                      onCheckedChange={(checked) => toggleCategory(category, checked === true)}
                    />
                    {t(`flows.settings.categories.${category}`)}
                  </label>
                ))}
              </div>
            </fieldset>

            <div className="space-y-2">
              <Label htmlFor="flow-json-body">{t("flows.settings.json.json")}</Label>
              <Textarea
                id="flow-json-body"
                value={json}
                onChange={(event) => {
                  setJson(event.target.value)
                  setJsonError(null)
                }}
                spellCheck={false}
                rows={16}
                className="font-mono text-xs"
                placeholder='{"version": "7.3", "screens": [...]}'
                aria-invalid={jsonError !== null}
              />
              {jsonError && <p className="text-sm text-destructive">{jsonError}</p>}
              {serverErrors.length > 0 && (
                <ul className="space-y-1 text-sm text-destructive">
                  {serverErrors.map((message, index) => (
                    <li key={index}>{message}</li>
                  ))}
                </ul>
              )}
              {validationErrors.length > 0 && (
                <div className="space-y-2 rounded-md border border-destructive/40 bg-destructive/5 p-3">
                  <p className="text-sm font-medium text-destructive">
                    {t("flows.settings.json.validationErrors", { count: validationErrors.length })}
                  </p>
                  <ul className="space-y-1 text-sm">
                    {validationErrors.map((error, index) => (
                      <li key={index} className="text-foreground">
                        {error.line_start !== undefined && (
                          <span className="mr-1 font-mono text-xs text-muted-foreground">
                            {t("flows.settings.json.line", { line: error.line_start })}
                          </span>
                        )}
                        {error.message}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>

            <details className="rounded-md border p-3 text-sm">
              <summary className="cursor-pointer font-medium">{t("flows.settings.json.helpTitle")}</summary>
              <div className="mt-3 space-y-2 text-muted-foreground">
                <p>{t("flows.settings.json.helpIntro")}</p>
                <ul className="list-disc space-y-1 pl-5">
                  <li>
                    <code className="font-mono text-xs">crm_action</code>:{" "}
                    {t("flows.settings.json.helpActions")}{" "}
                    <code className="font-mono text-xs">
                      prefill, appointment_dates, appointment_slots, book_appointment, complete
                    </code>
                  </li>
                  <li>
                    <code className="font-mono text-xs">next_screen</code>: {t("flows.settings.json.helpNextScreen")}
                  </li>
                  <li>
                    {t("flows.settings.json.helpPrefill")}{" "}
                    <code className="font-mono text-xs">contact_name, contact_email, contact_phone, cf_&lt;campo&gt;</code>
                  </li>
                  <li>{t("flows.settings.json.helpAppointment")}</li>
                  <li>{t("flows.settings.json.helpRouting")}</li>
                </ul>
              </div>
            </details>
          </div>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t("common.cancel")}
          </Button>
          <Button type="button" onClick={() => void save()} disabled={!canSave}>
            {saving && <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />}
            {t("common.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function prettyJson(raw: string): string {
  if (!raw.trim()) return ""
  try {
    return JSON.stringify(JSON.parse(raw), null, 2)
  } catch {
    return raw
  }
}
