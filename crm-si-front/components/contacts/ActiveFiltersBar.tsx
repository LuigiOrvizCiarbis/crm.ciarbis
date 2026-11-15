"use client"

import { useEffect, useRef, useState } from "react"
import type React from "react"
import { X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { getTags, type Tag } from "@/lib/api/tags"
import { CONTACT_SOURCES } from "@/components/contacts-compact-header"
import {
  EMPTY_CONTACT_FILTERS,
  withCustomFilter,
  type ContactFilters,
  type CustomFieldFilter,
} from "./contactFilters"
import { CUSTOM_FILTER_TRIGGER_ID } from "./CustomFieldFilterMenu"
import { CustomFieldFilterEditor, FilterSurface, summarizeCustomFilter } from "./CustomFieldFilterEditor"

const CHIP_REMOVE_SELECTOR = "[data-chip-remove]"

function isRemoveKey(event: React.KeyboardEvent): boolean {
  return event.key === "Backspace" || event.key === "Delete"
}

interface ChipProps {
  label: string
  summary: string
  removeLabel: string
  onRemove: () => void
}

const chipClass =
  "inline-flex h-7 max-w-full items-center rounded-full border border-border bg-background text-xs animate-in fade-in-0 duration-150 motion-reduce:animate-none pointer-coarse:h-9"
const chipPartClass =
  "flex h-full items-center outline-none transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"

function ChipContent({ label, summary }: { label: string; summary: string }) {
  return (
    <>
      <span className="shrink-0 text-muted-foreground">{label}:</span>
      <span className="min-w-0 truncate font-medium text-foreground">{summary}</span>
    </>
  )
}

function RemoveButton({ label, onRemove }: { label: string; onRemove: () => void }) {
  return (
    <button
      type="button"
      data-chip-remove
      className={`${chipPartClass} shrink-0 rounded-r-full pl-1 pr-2 text-muted-foreground hover:text-foreground`}
      onClick={onRemove}
      onKeyDown={(event) => {
        if (isRemoveKey(event)) onRemove()
      }}
      aria-label={label}
    >
      <X className="size-3.5" aria-hidden="true" />
    </button>
  )
}

/** Origen, etiquetas y audiencia se editan desde el header: acá solo se leen y se quitan. */
function StaticChip({ label, summary, removeLabel, onRemove }: ChipProps) {
  return (
    <div className={chipClass}>
      <span className="flex min-w-0 items-center gap-1 pl-3 pr-1.5">
        <ChipContent label={label} summary={summary} />
      </span>
      <RemoveButton label={removeLabel} onRemove={onRemove} />
    </div>
  )
}

interface CustomChipProps {
  field: ContactField
  filter: CustomFieldFilter
  onChange: (filter: CustomFieldFilter | undefined) => void
  onRemove: () => void
}

function CustomChip({ field, filter, onChange, onRemove }: CustomChipProps) {
  const { t, language } = useTranslation()
  const [open, setOpen] = useState(false)
  const summary = summarizeCustomFilter(filter, field, t, language)

  const trigger = (
    <button
      type="button"
      className={`${chipPartClass} min-w-0 gap-1 rounded-l-full pl-3 pr-1.5`}
      onKeyDown={(event) => {
        if (isRemoveKey(event)) onRemove()
      }}
      aria-label={t("contactsPage.filters.active.edit", { field: field.label, value: summary })}
    >
      <ChipContent label={field.label} summary={summary} />
    </button>
  )

  return (
    <div className={chipClass}>
      <FilterSurface open={open} onOpenChange={setOpen} trigger={trigger} title={field.label}>
        <CustomFieldFilterEditor
          field={field}
          value={filter}
          onChange={(next) => {
            onChange(next)
            if (!next) setOpen(false)
          }}
        />
      </FilterSurface>
      <RemoveButton label={t("contactsPage.filters.active.remove", { field: field.label })} onRemove={onRemove} />
    </div>
  )
}

/** Los chips de etiquetas muestran nombres, pero el filtro guarda slugs: se resuelven una vez. */
function useTagNames(slugs: string[]): Record<string, string> {
  const [tags, setTags] = useState<Tag[] | null>(null)
  const needsTags = slugs.length > 0 && tags === null

  useEffect(() => {
    if (!needsTags) return
    let cancelled = false
    getTags()
      .then((loaded) => {
        if (!cancelled) setTags(loaded)
      })
      // Sin nombres, el chip cae al slug: sigue siendo legible y quitable.
      .catch(() => {
        if (!cancelled) setTags([])
      })
    return () => {
      cancelled = true
    }
  }, [needsTags])

  return Object.fromEntries((tags ?? []).map((tag) => [tag.slug, tag.name]))
}

interface ActiveFiltersBarProps {
  filters: ContactFilters
  onChange: (filters: ContactFilters) => void
  fields: ContactField[]
  /** Total de resultados con estos filtros; se anuncia a lectores de pantalla. */
  total: number
  loading: boolean
}

/** Todos los filtros aplicados en un solo renglón legible, arriba de la tabla. */
export function ActiveFiltersBar({ filters, onChange, fields, total, loading }: ActiveFiltersBarProps) {
  const { t } = useTranslation()
  const containerRef = useRef<HTMLDivElement>(null)
  const tagNames = useTagNames(filters.tags)
  const fieldsByKey = new Map(fields.map((field) => [field.key, field]))

  // Tras quitar un chip, el foco pasa al que ocupó su lugar (o al anterior);
  // sin chips, vuelve al botón "Campos" para seguir filtrando con teclado.
  const removeAndRefocus = (chipIndex: number, apply: () => void) => {
    apply()
    requestAnimationFrame(() => {
      const removeButtons = containerRef.current?.querySelectorAll<HTMLElement>(CHIP_REMOVE_SELECTOR)
      const next = removeButtons?.[chipIndex] ?? removeButtons?.[chipIndex - 1]
      if (next) next.focus()
      else document.getElementById(CUSTOM_FILTER_TRIGGER_ID)?.focus()
    })
  }

  const chips: React.ReactNode[] = []

  if (filters.source !== "all") {
    const index = chips.length
    chips.push(
      <StaticChip
        key="source"
        label={t("contactsPage.filters.source")}
        summary={CONTACT_SOURCES.find((source) => source.value === filters.source)?.label ?? filters.source}
        removeLabel={t("contactsPage.filters.active.remove", { field: t("contactsPage.filters.source") })}
        onRemove={() => removeAndRefocus(index, () => onChange({ ...filters, source: "all" }))}
      />,
    )
  }

  if (filters.audience === "clients") {
    const index = chips.length
    chips.push(
      <StaticChip
        key="audience"
        label={t("contactsPage.filters.audience.label")}
        summary={t("contactsPage.filters.audience.clients")}
        removeLabel={t("contactsPage.filters.active.remove", { field: t("contactsPage.filters.audience.label") })}
        onRemove={() => removeAndRefocus(index, () => onChange({ ...filters, audience: "all" }))}
      />,
    )
  }

  if (filters.tags.length > 0) {
    const index = chips.length
    const names = filters.tags.map((slug) => tagNames[slug] ?? slug)
    chips.push(
      <StaticChip
        key="tags"
        label={t("contactsPage.filters.active.tags")}
        summary={names.length <= 2 ? names.join(", ") : t("contactsPage.filters.custom.summary.more", { first: names[0], count: names.length - 1 })}
        removeLabel={t("contactsPage.filters.active.remove", { field: t("contactsPage.filters.active.tags") })}
        onRemove={() => removeAndRefocus(index, () => onChange({ ...filters, tags: [] }))}
      />,
    )
  }

  for (const [key, filter] of Object.entries(filters.custom)) {
    const field = fieldsByKey.get(key)
    if (!field) continue
    const index = chips.length
    const setFilter = (next: CustomFieldFilter | undefined) =>
      onChange({ ...filters, custom: withCustomFilter(filters.custom, key, next) })
    chips.push(
      <CustomChip
        key={`custom:${key}`}
        field={field}
        filter={filter}
        onChange={setFilter}
        onRemove={() => removeAndRefocus(index, () => setFilter(undefined))}
      />,
    )
  }

  if (chips.length === 0) return null

  return (
    <div ref={containerRef} className="flex flex-wrap items-center gap-1.5" role="group" aria-label={t("contactsPage.filters.active.label")}>
      {chips}
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-7 px-2 text-xs text-muted-foreground pointer-coarse:h-9"
        onClick={() => {
          onChange({ ...EMPTY_CONTACT_FILTERS, search: filters.search })
          requestAnimationFrame(() => document.getElementById(CUSTOM_FILTER_TRIGGER_ID)?.focus())
        }}
      >
        {t("contactsPage.filters.active.clearAll")}
      </Button>
      <span className="sr-only" aria-live="polite">
        {loading ? "" : t(`contactsPage.filters.active.results_${total === 1 ? "one" : "other"}`, { count: total })}
      </span>
    </div>
  )
}
