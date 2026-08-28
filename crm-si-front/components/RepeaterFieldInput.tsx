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
import { cn } from "@/lib/utils"
import { CurrencyInput } from "@/components/CurrencyInput"

type Field = ContactField | ProductField

interface RepeaterFieldInputProps {
  field: Field
  value: unknown
  onChange: (next: unknown) => void
  disabled?: boolean
  compact?: boolean
}

export function RepeaterFieldInput({ field, value, onChange, disabled, compact = false }: RepeaterFieldInputProps) {
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
    <div
      className={cn(
        "overflow-hidden rounded-lg bg-background",
        compact ? "w-[min(30rem,calc(100vw-3rem))] max-w-none" : "w-full border",
      )}
    >
      <div className="flex items-center justify-between gap-3 border-b px-3 py-2.5">
        <div className="min-w-0">
          <Label className="block truncate text-sm font-semibold">
            {field.label}
            {field.is_required ? <span className="ml-1 text-destructive">*</span> : null}
          </Label>
          <p className="mt-0.5 text-xs tabular-nums text-muted-foreground">
            {rows.length} de {maxItems} filas
          </p>
        </div>
        <Button
          type="button"
          size="sm"
          onClick={addRow}
          disabled={disabled || rows.length >= maxItems || subfields.length === 0}
          className="shrink-0"
        >
          <Plus className="size-4" />
          {t("fields.repeater.addRow")}
        </Button>
      </div>

      <div className="max-h-[min(22rem,50vh)] space-y-2 overflow-y-auto overscroll-contain p-2.5">
        {rows.map((row, rowIndex) => (
          <div key={rowIndex} className="rounded-md border bg-muted/20 p-2.5">
            <div className="mb-2.5 flex items-center justify-between gap-3">
              <span className="text-xs font-semibold tabular-nums text-muted-foreground">
                {t("fields.repeater.row", { index: rowIndex + 1 })}
              </span>
              <div className="flex items-center gap-0.5" role="group" aria-label={`Acciones de la fila ${rowIndex + 1}`}>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-8"
                  onClick={() => moveRow(rowIndex, -1)}
                  disabled={disabled || rowIndex === 0}
                  aria-label={t("fields.repeater.moveRowUp")}
                  title={t("fields.repeater.moveRowUp")}
                >
                  <ChevronUp className="size-4" />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-8"
                  onClick={() => moveRow(rowIndex, 1)}
                  disabled={disabled || rowIndex === rows.length - 1}
                  aria-label={t("fields.repeater.moveRowDown")}
                  title={t("fields.repeater.moveRowDown")}
                >
                  <ChevronDown className="size-4" />
                </Button>
                <span className="mx-1 h-4 w-px bg-border" aria-hidden />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-8 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:text-destructive"
                  onClick={() => removeRow(rowIndex)}
                  disabled={disabled || rows.length <= minItems}
                  aria-label={t("fields.repeater.removeRow")}
                  title={t("fields.repeater.removeRow")}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            </div>
            <div className="grid gap-2.5 sm:grid-cols-2">
              {subfields.map((subfield) => (
                <RepeaterSubfieldInput
                  key={subfield.key}
                  field={subfield}
                  rowIndex={rowIndex}
                  value={row[subfield.key!]}
                  disabled={disabled}
                  onChange={(next) => updateCell(rowIndex, subfield.key!, next)}
                />
              ))}
            </div>
          </div>
        ))}
        {rows.length < minItems ? <p className="px-1 text-xs text-destructive">{t("fields.repeater.minimumError", { count: minItems })}</p> : null}
        {rows.length === 0 ? <p className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">{t("fields.repeater.emptyRows")}</p> : null}
      </div>
    </div>
  )
}

function RepeaterSubfieldInput({ field, rowIndex, value, disabled, onChange }: { field: RepeaterSubfield; rowIndex: number; value: unknown; disabled?: boolean; onChange: (value: unknown) => void }) {
  const id = `repeater-${field.key}-${rowIndex}`
  const label = <Label htmlFor={id} className="text-xs font-medium text-muted-foreground">{field.label}{field.is_required ? <span className="ml-1 text-destructive">*</span> : null}</Label>
  if (field.type === "boolean") return <div className="flex min-h-14 items-center justify-between gap-3 rounded-md border bg-background px-3"><Label htmlFor={id} className="text-sm font-medium">{field.label}</Label><Switch id={id} checked={Boolean(value)} onCheckedChange={onChange} disabled={disabled} /></div>
  if (field.type === "select") return <div className="space-y-1.5">{label}<Select value={typeof value === "string" ? value : ""} onValueChange={(next) => onChange(next)} disabled={disabled}><SelectTrigger id={id} className="w-full"><SelectValue placeholder="—" /></SelectTrigger><SelectContent>{(field.options?.choices ?? []).map((choice) => <SelectItem key={choice} value={choice}>{choice}</SelectItem>)}</SelectContent></Select></div>
  if (field.type === "currency") return <div className="min-w-0 space-y-1.5">{label}<CurrencyInput id={id} value={value} currency={field.options?.currency} onChange={onChange} disabled={disabled} /></div>
  return <div className="min-w-0 space-y-1.5">{label}<Input className="w-full" id={id} type={field.type === "number" ? "number" : field.type === "date" ? "date" : field.type === "email" ? "email" : field.type === "url" ? "url" : "text"} inputMode={field.type === "phone" ? "tel" : undefined} value={value === null || value === undefined ? "" : String(value)} onChange={(event) => onChange(field.type === "number" ? (event.target.value === "" ? null : Number(event.target.value)) : event.target.value)} disabled={disabled} /></div>
}
