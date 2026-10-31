"use client"

import { useMemo, useState } from "react"
import { Loader2 } from "lucide-react"

import { Checkbox } from "@/components/ui/checkbox"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { ScrollArea } from "@/components/ui/scroll-area"
import { CustomFieldInput } from "@/components/CustomFieldInput"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { bulkUpdateContactFields, type ContactUpdate } from "@/lib/api/contacts"
import type { ContactField } from "@/lib/api/contact-fields"

interface BulkFieldsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  selectedIds: number[]
  fields: ContactField[]
  onSuccess: (failedIds: number[]) => void
}

const STANDARD_FIELDS: ContactField[] = [
  { id: -1, key: "name", label: "Nombre", type: "text", options: null, is_required: true, is_unique: false, is_system: true, display_order: 0 },
  { id: -2, key: "phone", label: "Teléfono", type: "phone", options: null, is_required: false, is_unique: false, is_system: true, display_order: 1 },
  { id: -3, key: "email", label: "Email", type: "email", options: null, is_required: false, is_unique: false, is_system: true, display_order: 2 },
  { id: -4, key: "source", label: "Origen", type: "select", options: { choices: ["manual", "whatsapp", "instagram", "facebook"] }, is_required: true, is_unique: false, is_system: true, display_order: 3 },
]

function canClear(field: ContactField): boolean {
  return !field.is_required && field.key !== "source"
}

export function BulkFieldsDialog({ open, onOpenChange, selectedIds, fields, onSuccess }: BulkFieldsDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set())
  const [clearedKeys, setClearedKeys] = useState<Set<string>>(new Set())
  const [values, setValues] = useState<Record<string, unknown>>({})
  const [submitting, setSubmitting] = useState(false)

  const editableFields = useMemo(
    () => [...STANDARD_FIELDS, ...fields.filter((field) => !field.is_unique && field.type !== "file" && field.type !== "repeater")],
    [fields],
  )

  const toggleField = (key: string, checked: boolean) => {
    setSelectedKeys((current) => {
      const next = new Set(current)
      if (checked) next.add(key)
      else next.delete(key)
      return next
    })
    if (checked && !Object.prototype.hasOwnProperty.call(values, key)) {
      const field = editableFields.find((item) => item.key === key)
      const initialValue = field?.type === "boolean" ? false : field?.type === "multi_select" ? [] : ""
      setValues((current) => ({ ...current, [key]: initialValue }))
    }
    if (!checked) {
      setClearedKeys((current) => {
        const next = new Set(current)
        next.delete(key)
        return next
      })
    }
  }

  const toggleClear = (key: string, checked: boolean) => {
    setClearedKeys((current) => {
      const next = new Set(current)
      if (checked) next.add(key)
      else next.delete(key)
      return next
    })
  }

  const buildUpdates = (): ContactUpdate => {
    const updates: ContactUpdate = {}
    const customData: Record<string, unknown> = {}
    for (const field of editableFields) {
      if (!selectedKeys.has(field.key)) continue
      const value = clearedKeys.has(field.key) ? null : values[field.key]
      if (field.is_system) {
        updates[field.key as keyof ContactUpdate] = value as never
      } else {
        customData[field.key] = value
      }
    }
    if (Object.keys(customData).length > 0) updates.custom_data = customData
    return updates
  }

  const handleApply = async () => {
    if (selectedKeys.size === 0 || selectedIds.length === 0 || submitting) return
    setSubmitting(true)
    try {
      const result = await bulkUpdateContactFields({ ids: selectedIds, updates: buildUpdates() })
      if (result.failed > 0) {
        addToast({
          type: "info",
          title: t("contactsPage.bulk.fields.result.partial", { updated: result.updated, failed: result.failed }),
          description: result.failures.slice(0, 3).map((failure) => failure.reason).join(" "),
        })
      } else {
        addToast({ type: "success", title: t("contactsPage.bulk.fields.result.success", { updated: result.updated }) })
      }
      onOpenChange(false)
      onSuccess(result.failures.map((failure) => failure.id))
    } catch (error) {
      addToast({
        type: "error",
        title: t("contactsPage.bulk.fields.errors.apply"),
        description: error instanceof Error ? error.message : "",
      })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !submitting && onOpenChange(next)}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{t("contactsPage.bulk.fields.title")}</DialogTitle>
          <DialogDescription>{t("contactsPage.bulk.fields.subtitle", { count: selectedIds.length })}</DialogDescription>
        </DialogHeader>

        <p className="text-sm text-muted-foreground">{t("contactsPage.bulk.fields.hint")}</p>
        <ScrollArea className="max-h-[min(55vh,32rem)] pr-4">
          <div className="space-y-3 py-1">
            {editableFields.map((field) => {
              const selected = selectedKeys.has(field.key)
              const cleared = clearedKeys.has(field.key)
              const clearable = canClear(field)
              return (
                <div key={field.key} className="rounded-lg border bg-card p-3">
                  <div className="flex items-center gap-2">
                    <Checkbox
                      id={`bulk-field-${field.key}`}
                      checked={selected}
                      onCheckedChange={(checked) => toggleField(field.key, checked === true)}
                    />
                    <Label htmlFor={`bulk-field-${field.key}`} className="cursor-pointer font-medium">
                      {field.label}
                    </Label>
                    {selected && clearable ? (
                      <label htmlFor={`bulk-clear-${field.key}`} className="ml-auto flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
                        <Checkbox id={`bulk-clear-${field.key}`} checked={cleared} onCheckedChange={(checked) => toggleClear(field.key, checked === true)} />
                        {t("contactsPage.bulk.fields.clear")}
                      </label>
                    ) : null}
                  </div>
                  {selected ? (
                    <CustomFieldInput
                      field={field}
                      value={cleared ? null : values[field.key]}
                      onChange={(value) => setValues((current) => ({ ...current, [field.key]: value }))}
                      disabled={cleared || submitting}
                      className="mt-3"
                    />
                  ) : null}
                </div>
              )
            })}
          </div>
        </ScrollArea>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>{t("contactsPage.bulk.dialog.cancel")}</Button>
          <Button onClick={() => void handleApply()} disabled={submitting || selectedKeys.size === 0}>
            {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
            {t("contactsPage.bulk.fields.apply")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
