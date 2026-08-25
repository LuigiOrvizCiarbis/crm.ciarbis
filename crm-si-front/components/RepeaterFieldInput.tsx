"use client"

import { useMemo } from "react"
import { ChevronDown, ChevronUp, Plus, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import type { ContactField, RepeaterSubfield } from "@/lib/api/contact-fields"
import type { ProductField } from "@/lib/api/product-fields"
import { useTranslation } from "@/hooks/useTranslation"

type Field = ContactField | ProductField

interface RepeaterFieldInputProps {
  field: Field
  value: unknown
  onChange: (next: unknown) => void
  disabled?: boolean
}

export function RepeaterFieldInput({ field, value, onChange, disabled }: RepeaterFieldInputProps) {
  const { t } = useTranslation()
  const options = field.options ?? {}
  const subfields = (options.fields ?? []).filter((subfield) => subfield.is_active !== false && subfield.key)
  const rows = useMemo(
    () => (Array.isArray(value) ? value.filter((row): row is Record<string, unknown> => Boolean(row && typeof row === "object")) : []),
    [value],
  )
  const maxItems = options.max_items ?? 10
  const minItems = field.is_required ? Math.max(1, options.min_items ?? 0) : (options.min_items ?? 0)

  const updateRows = (next: Record<string, unknown>[]) => onChange(next)
  const addRow = () => {
    if (rows.length >= maxItems) return
    updateRows([...rows, {}])
  }
  const updateCell = (rowIndex: number, key: string, next: unknown) => {
    updateRows(rows.map((row, index) => index === rowIndex ? { ...row, [key]: next } : row))
  }
  const removeRow = (rowIndex: number) => updateRows(rows.filter((_, index) => index !== rowIndex))
  const moveRow = (rowIndex: number, direction: -1 | 1) => {
    const target = rowIndex + direction
    if (target < 0 || target >= rows.length) return
    const next = [...rows]
    ;[next[rowIndex], next[target]] = [next[target], next[rowIndex]]
    updateRows(next)
  }

  return (
    <div className="space-y-3 rounded-lg border bg-muted/20 p-3">
      <div className="flex items-center justify-between gap-3">
        <div>
          <Label className="text-sm">{field.label}{field.is_required ? <span className="ml-1 text-destructive">*</span> : null}</Label>
          <p className="text-xs text-muted-foreground">{rows.length} / {maxItems}</p>
        </div>
        <Button type="button" size="sm" variant="outline" onClick={addRow} disabled={disabled || rows.length >= maxItems || subfields.length === 0}>
          <Plus className="mr-1 size-4" />{t("fields.repeater.addRow")}
        </Button>
      </div>

      {rows.map((row, rowIndex) => (
        <div key={rowIndex} className="space-y-3 rounded-md border bg-background p-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">{t("fields.repeater.row", { index: rowIndex + 1 })}</span>
            <div className="flex items-center gap-1">
              <Button type="button" variant="ghost" size="icon" onClick={() => moveRow(rowIndex, -1)} disabled={disabled || rowIndex === 0} aria-label={t("fields.repeater.moveRowUp")}><ChevronUp className="size-4" /></Button>
              <Button type="button" variant="ghost" size="icon" onClick={() => moveRow(rowIndex, 1)} disabled={disabled || rowIndex === rows.length - 1} aria-label={t("fields.repeater.moveRowDown")}><ChevronDown className="size-4" /></Button>
              <Button type="button" variant="ghost" size="icon" className="text-destructive" onClick={() => removeRow(rowIndex)} disabled={disabled || rows.length <= minItems} aria-label={t("fields.repeater.removeRow")}><Trash2 className="size-4" /></Button>
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            {subfields.map((subfield) => (
              <RepeaterSubfieldInput key={subfield.key} field={subfield} value={row[subfield.key!]} disabled={disabled} onChange={(next) => updateCell(rowIndex, subfield.key!, next)} />
            ))}
          </div>
        </div>
      ))}
      {rows.length < minItems ? <p className="text-xs text-destructive">{t("fields.repeater.minimumError", { count: minItems })}</p> : null}
      {rows.length === 0 ? <p className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">{t("fields.repeater.emptyRows")}</p> : null}
    </div>
  )
}

function RepeaterSubfieldInput({ field, value, disabled, onChange }: { field: RepeaterSubfield; value: unknown; disabled?: boolean; onChange: (value: unknown) => void }) {
  const id = `repeater-${field.key}`
  const label = <Label htmlFor={id} className="text-xs">{field.label}{field.is_required ? <span className="ml-1 text-destructive">*</span> : null}</Label>
  if (field.type === "boolean") return <div className="space-y-1"><Label className="text-xs">{field.label}</Label><Switch id={id} checked={Boolean(value)} onCheckedChange={onChange} disabled={disabled} /></div>
  if (field.type === "select") return <div className="space-y-1">{label}<Select value={typeof value === "string" ? value : ""} onValueChange={(next) => onChange(next)} disabled={disabled}><SelectTrigger id={id}><SelectValue placeholder="—" /></SelectTrigger><SelectContent>{(field.options?.choices ?? []).map((choice) => <SelectItem key={choice} value={choice}>{choice}</SelectItem>)}</SelectContent></Select></div>
  return <div className="space-y-1">{label}<Input id={id} type={field.type === "number" ? "number" : field.type === "date" ? "date" : field.type === "email" ? "email" : field.type === "url" ? "url" : "text"} inputMode={field.type === "phone" ? "tel" : undefined} value={value === null || value === undefined ? "" : String(value)} onChange={(event) => onChange(field.type === "number" ? (event.target.value === "" ? null : Number(event.target.value)) : event.target.value)} disabled={disabled} /></div>
}
