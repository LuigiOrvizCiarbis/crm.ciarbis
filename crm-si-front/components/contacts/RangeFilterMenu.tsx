"use client"

import { useMemo, useState } from "react"
import { CalendarRange, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"

export interface RangeFilterValue {
  from?: string
  to?: string
}

interface RangeFilterMenuProps {
  fields: ContactField[]
  value: Record<string, RangeFilterValue>
  onChange: (value: Record<string, RangeFilterValue>) => void
  align?: "start" | "center" | "end"
}

/**
 * Filtro de rango sobre campos custom Date/Number (ej. vencimiento de pago).
 * Solo esos dos tipos aceptan rango en el backend
 * (ContactController::index, whitelist por tipo) — el resto de los campos
 * custom ni siquiera aparecen en el selector.
 */
export function RangeFilterMenu({ fields, value, onChange, align = "end" }: RangeFilterMenuProps) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [activeKey, setActiveKey] = useState<string | null>(null)

  const rangeableFields = useMemo(
    () => fields.filter((f) => f.type === "date" || f.type === "number"),
    [fields],
  )

  const activeFilterCount = useMemo(
    () => Object.values(value).filter((v) => v.from || v.to).length,
    [value],
  )

  if (rangeableFields.length === 0) return null

  const selectedField = rangeableFields.find((f) => f.key === activeKey) ?? rangeableFields[0]

  const setRange = (key: string, range: RangeFilterValue) => {
    const next = { ...value }
    if (!range.from && !range.to) {
      delete next[key]
    } else {
      next[key] = range
    }
    onChange(next)
  }

  const clearAll = () => onChange({})

  return (
    <div className="flex items-center gap-2">
      <DropdownMenu open={open} onOpenChange={setOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm" className="gap-2 bg-transparent">
            <CalendarRange className="h-4 w-4" />
            {t("contactsPage.filters.range.label")}
            {activeFilterCount > 0 && (
              <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground">
                {activeFilterCount}
              </span>
            )}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align={align} className="w-72" onCloseAutoFocus={(e) => e.preventDefault()}>
          <DropdownMenuLabel>{t("contactsPage.filters.range.menuTitle")}</DropdownMenuLabel>
          <DropdownMenuSeparator />
          <div className="px-2 py-2 space-y-3">
            <Select value={selectedField.key} onValueChange={setActiveKey}>
              <SelectTrigger className="h-9">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {rangeableFields.map((field) => (
                  <SelectItem key={field.key} value={field.key}>
                    {field.label}
                    {(value[field.key]?.from || value[field.key]?.to) && " •"}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <div className="grid grid-cols-2 gap-2">
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">
                  {t("contactsPage.filters.range.from")}
                </label>
                <Input
                  type={selectedField.type === "date" ? "date" : "number"}
                  value={value[selectedField.key]?.from ?? ""}
                  onChange={(e) =>
                    setRange(selectedField.key, {
                      ...value[selectedField.key],
                      from: e.target.value || undefined,
                    })
                  }
                  className="h-9"
                />
              </div>
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">
                  {t("contactsPage.filters.range.to")}
                </label>
                <Input
                  type={selectedField.type === "date" ? "date" : "number"}
                  value={value[selectedField.key]?.to ?? ""}
                  onChange={(e) =>
                    setRange(selectedField.key, {
                      ...value[selectedField.key],
                      to: e.target.value || undefined,
                    })
                  }
                  className="h-9"
                />
              </div>
            </div>
          </div>
        </DropdownMenuContent>
      </DropdownMenu>
      {activeFilterCount > 0 && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 px-2 text-muted-foreground"
          onClick={clearAll}
        >
          <X className="h-4 w-4" />
        </Button>
      )}
    </div>
  )
}
