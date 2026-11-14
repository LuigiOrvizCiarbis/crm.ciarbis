"use client"

import { useMemo, useState } from "react"
import { ListFilter } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { filterKindForField, withCustomFilter, type CustomFieldFilters } from "./contactFilters"
import { CustomFieldFilterEditor, FieldTypeIcon, FilterSurface, summarizeCustomFilter } from "./CustomFieldFilterEditor"

/** La barra de filtros activos devuelve el foco acá al quitar el último chip. */
export const CUSTOM_FILTER_TRIGGER_ID = "contacts-custom-filter-trigger"

const FIELD_SEARCH_THRESHOLD = 7

interface CustomFieldFilterMenuProps {
  fields: ContactField[]
  value: CustomFieldFilters
  onChange: (value: CustomFieldFilters) => void
  align?: "start" | "center" | "end"
  /** Teléfono: botón de ícono de 44px junto al buscador, como el de filtros. */
  compact?: boolean
}

/** "Campos": elegir un campo custom y editar su filtro, en dos pasos dentro del mismo popover. */
export function CustomFieldFilterMenu({ fields, value, onChange, align = "end", compact = false }: CustomFieldFilterMenuProps) {
  const { t, language } = useTranslation()
  const [open, setOpen] = useState(false)
  const [activeKey, setActiveKey] = useState<string | null>(null)

  const filterableFields = useMemo(() => fields.filter((field) => filterKindForField(field) !== null), [fields])

  if (filterableFields.length === 0) return null

  const activeCount = Object.keys(value).length
  const activeField = filterableFields.find((field) => field.key === activeKey)

  const handleOpenChange = (next: boolean) => {
    setOpen(next)
    if (!next) setActiveKey(null)
  }

  const label = t("contactsPage.filters.custom.label")
  const accessibleLabel = activeCount > 0 ? t("contactsPage.filters.custom.labelActive", { count: activeCount }) : label

  const trigger = compact ? (
    <Button
      id={CUSTOM_FILTER_TRIGGER_ID}
      variant="outline"
      className="relative size-11 shrink-0 bg-transparent"
      aria-label={accessibleLabel}
    >
      <ListFilter className="size-4" aria-hidden="true" />
      {activeCount > 0 && (
        <span
          className="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-medium tabular-nums text-primary-foreground"
          aria-hidden="true"
        >
          {activeCount}
        </span>
      )}
    </Button>
  ) : (
    <Button id={CUSTOM_FILTER_TRIGGER_ID} variant="outline" size="sm" className="gap-2 bg-transparent" aria-label={accessibleLabel}>
      <ListFilter className="h-4 w-4" aria-hidden="true" />
      {label}
      {activeCount > 0 && (
        <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-primary-foreground" aria-hidden="true">
          {activeCount}
        </span>
      )}
    </Button>
  )

  return (
    <FilterSurface
      open={open}
      onOpenChange={handleOpenChange}
      trigger={trigger}
      title={t("contactsPage.filters.custom.menuTitle")}
      align={align}
      onEscapeKeyDown={(event) => {
        // Esc primero vuelve a la lista; recién desde la lista cierra.
        if (activeKey === null) return
        event.preventDefault()
        setActiveKey(null)
      }}
    >
      {activeField ? (
        <CustomFieldFilterEditor
          key={activeField.key}
          field={activeField}
          value={value[activeField.key]}
          onChange={(filter) => onChange(withCustomFilter(value, activeField.key, filter))}
          onBack={() => setActiveKey(null)}
        />
      ) : (
        <Command>
          {filterableFields.length > FIELD_SEARCH_THRESHOLD && (
            <CommandInput placeholder={t("contactsPage.filters.custom.search")} />
          )}
          <CommandList>
            <CommandEmpty>{t("contactsPage.filters.custom.noFields")}</CommandEmpty>
            <CommandGroup heading={t("contactsPage.filters.custom.menuTitle")}>
              {filterableFields.map((field) => {
                const filter = value[field.key]
                return (
                  <CommandItem
                    key={field.key}
                    value={`${field.label} ${field.key}`}
                    onSelect={() => setActiveKey(field.key)}
                    className="gap-2.5 pointer-coarse:min-h-11"
                  >
                    <FieldTypeIcon type={field.type} className="size-4 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate">{field.label}</span>
                    {filter && (
                      <span className="max-w-[45%] truncate text-xs font-medium text-foreground">
                        {summarizeCustomFilter(filter, field, t, language)}
                      </span>
                    )}
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      )}
    </FilterSurface>
  )
}
