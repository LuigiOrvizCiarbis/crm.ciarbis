"use client"

import { useEffect, useState } from "react"
import { Loader2, Plus, Trash2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useToast } from "@/components/Toast"
import { CreateContactFieldDialog } from "@/components/flows/CreateContactFieldDialog"
import type { WhatsAppFlow, WhatsAppFlowFieldMapping } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { getContactFields, type ContactField } from "@/lib/api/contact-fields"
import { ApiValidationError, updateFlow } from "@/lib/api/whatsapp-flows"
import { CREATABLE_FIELD_TYPES } from "@/lib/flow-builder/compat"

interface FlowFieldMappingDialogProps {
  flow: WhatsAppFlow | null
  onOpenChange: (open: boolean) => void
  onSaved: (flow: WhatsAppFlow) => void
}

/** Mismos límites que UpdateWhatsAppFlowRequest. */
const MAX_MAPPINGS = 50
const RESPONSE_KEY_PATTERN = /^[A-Za-z0-9_.]+$/
/** Tipos que un formulario no puede completar. */
const UNMAPPABLE_TYPES: Partial<Record<ContactField["type"], true>> = { file: true, repeater: true }
const CREATE_FIELD = "__create__"

/** Etiqueta sugerida a partir de la clave de la respuesta: `fecha_nacimiento` → "Fecha nacimiento". */
function labelFromKey(key: string): string {
  const words = key.trim().replace(/[_.]+/g, " ").trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function FlowFieldMappingDialog({ flow, onOpenChange, onSaved }: FlowFieldMappingDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [rows, setRows] = useState<WhatsAppFlowFieldMapping[]>([])
  const [fields, setFields] = useState<ContactField[]>([])
  const [loadingFields, setLoadingFields] = useState(false)
  const [saving, setSaving] = useState(false)
  const [serverErrors, setServerErrors] = useState<string[]>([])
  const canCreateField = usePermission("contact_fields.manage")
  /** Fila para la que se está creando un campo. */
  const [creatingFor, setCreatingFor] = useState<number | null>(null)

  const open = flow !== null

  useEffect(() => {
    if (!flow) return
    setRows(flow.field_mappings.length > 0 ? flow.field_mappings : [{ response_key: "", target: "" }])
    setServerErrors([])
    let cancelled = false
    setLoadingFields(true)
    getContactFields()
      .then((response) => {
        if (!cancelled) setFields(response.data.filter((field) => !UNMAPPABLE_TYPES[field.type]))
      })
      .catch((error) => {
        if (cancelled) return
        addToast({
          type: "error",
          title: t("flows.settings.mapping.fieldsError"),
          description: error instanceof Error ? error.message : undefined,
        })
      })
      .finally(() => {
        if (!cancelled) setLoadingFields(false)
      })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [flow?.id])

  const updateRow = (index: number, patch: Partial<WhatsAppFlowFieldMapping>) => {
    setRows((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  // Filas vacías se ignoran; una fila a medio completar bloquea el guardado.
  const filled = rows.filter((row) => row.response_key.trim() || row.target)
  const invalidKey = filled.some((row) => !RESPONSE_KEY_PATTERN.test(row.response_key.trim()) || row.response_key.trim().length > 120)
  const missingTarget = filled.some((row) => !row.target)
  const canSave = !saving && !invalidKey && !missingTarget && filled.length <= MAX_MAPPINGS

  const save = async () => {
    if (!flow) return
    setSaving(true)
    setServerErrors([])
    try {
      const saved = await updateFlow(flow.id, {
        field_mappings: filled.map((row) => ({ response_key: row.response_key.trim(), target: row.target })),
      })
      onSaved(saved)
      addToast({ type: "success", title: t("flows.settings.mapping.saved") })
      onOpenChange(false)
    } catch (error) {
      if (error instanceof ApiValidationError) setServerErrors(Object.values(error.errors).flat())
      addToast({
        type: "error",
        title: t("flows.settings.mapping.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t("flows.settings.mapping.title", { name: flow?.name ?? "" })}</DialogTitle>
          <DialogDescription>{t("flows.settings.mapping.description")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <div className="hidden grid-cols-[1fr_1fr_auto] gap-2 text-xs font-medium text-muted-foreground sm:grid">
            <span>{t("flows.settings.mapping.responseKey")}</span>
            <span>{t("flows.settings.mapping.target")}</span>
            <span className="w-9" />
          </div>

          {rows.map((row, index) => {
            const key = row.response_key.trim()
            const keyInvalid = key.length > 0 && !RESPONSE_KEY_PATTERN.test(key)
            return (
              <div key={index} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                <div className="space-y-1">
                  <Input
                    value={row.response_key}
                    maxLength={120}
                    placeholder={t("flows.settings.mapping.responseKeyPlaceholder")}
                    aria-label={t("flows.settings.mapping.responseKey")}
                    aria-invalid={keyInvalid}
                    className="font-mono text-sm"
                    onChange={(event) => updateRow(index, { response_key: event.target.value })}
                  />
                  {keyInvalid && (
                    <p className="text-xs text-destructive">{t("flows.settings.mapping.invalidKey")}</p>
                  )}
                </div>
                <Select
                  value={row.target || undefined}
                  onValueChange={(value) => (value === CREATE_FIELD ? setCreatingFor(index) : updateRow(index, { target: value }))}
                  disabled={loadingFields}
                >
                  <SelectTrigger aria-label={t("flows.settings.mapping.target")} className="w-full">
                    <SelectValue placeholder={t("flows.settings.mapping.selectTarget")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectLabel>{t("flows.settings.mapping.contactGroup")}</SelectLabel>
                      <SelectItem value="contact.name">{t("flows.settings.mapping.contactName")}</SelectItem>
                      <SelectItem value="contact.email">{t("flows.settings.mapping.contactEmail")}</SelectItem>
                    </SelectGroup>
                    {fields.length > 0 && (
                      <SelectGroup>
                        <SelectLabel>{t("flows.settings.mapping.customGroup")}</SelectLabel>
                        {fields.map((field) => (
                          <SelectItem key={field.key} value={`custom_data.${field.key}`}>
                            {field.label}
                          </SelectItem>
                        ))}
                      </SelectGroup>
                    )}
                    {canCreateField && (
                      <>
                        <SelectSeparator />
                        <SelectItem value={CREATE_FIELD} className="text-primary focus:text-primary">
                          <span className="inline-flex items-center gap-2">
                            <Plus className="size-4" aria-hidden />
                            {t("flows.settings.mapping.createField")}
                          </span>
                        </SelectItem>
                      </>
                    )}
                  </SelectContent>
                </Select>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={t("flows.settings.mapping.remove")}
                  onClick={() => setRows((current) => current.filter((_, i) => i !== index))}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            )
          })}

          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={rows.length >= MAX_MAPPINGS}
            onClick={() => setRows((current) => [...current, { response_key: "", target: "" }])}
          >
            <Plus className="size-4" />
            {t("flows.settings.mapping.add")}
          </Button>

          <p className="text-xs text-muted-foreground">{t("flows.settings.mapping.hint")}</p>

          {serverErrors.length > 0 && (
            <ul className="space-y-1 text-sm text-destructive">
              {serverErrors.map((message, index) => (
                <li key={index}>{message}</li>
              ))}
            </ul>
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t("common.cancel")}
          </Button>
          <Button type="button" onClick={() => void save()} disabled={!canSave}>
            {saving && <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />}
            {t("common.save")}
          </Button>
        </DialogFooter>
        {creatingFor !== null && (
          <CreateContactFieldDialog
            onOpenChange={(next) => !next && setCreatingFor(null)}
            types={CREATABLE_FIELD_TYPES}
            initialLabel={labelFromKey(rows[creatingFor]?.response_key ?? "")}
            choicesHint={t("flows.settings.mapping.newFieldChoicesHint")}
            onCreated={(field) => {
              setFields((current) => [...current, field])
              updateRow(creatingFor, { target: `custom_data.${field.key}` })
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
