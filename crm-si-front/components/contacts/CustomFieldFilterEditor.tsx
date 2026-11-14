"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import type React from "react"
import { format, isValid, parseISO } from "date-fns"
import { enUS, es } from "date-fns/locale"
import {
  ArrowLeft,
  Banknote,
  Calendar,
  Hash,
  Link,
  List,
  ListChecks,
  Mail,
  Phone,
  ToggleLeft,
  Type,
  type LucideIcon,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import { formatCustomChoice } from "@/components/CustomFieldInput"
import { useIsMobile } from "@/hooks/use-mobile"
import { useTranslation } from "@/hooks/useTranslation"
import { formatCurrency } from "@/lib/currency"
import type { ContactField, ContactFieldType } from "@/lib/api/contact-fields"
import type { Language } from "@/lib/i18n"
import { filterKindForField, type CustomFieldFilter } from "./contactFilters"

type Translate = (key: string, params?: Record<string, string | number>) => string

const FIELD_TYPE_ICONS: Partial<Record<ContactFieldType, LucideIcon>> = {
  text: Type,
  email: Mail,
  url: Link,
  phone: Phone,
  number: Hash,
  currency: Banknote,
  date: Calendar,
  boolean: ToggleLeft,
  select: List,
  multi_select: ListChecks,
}

export function FieldTypeIcon({ type, className }: { type: ContactFieldType; className?: string }) {
  const Icon = FIELD_TYPE_ICONS[type] ?? Type
  return <Icon className={className} aria-hidden="true" />
}

const CHOICE_SEARCH_THRESHOLD = 8
const APPLY_DELAY_MS = 300

/**
 * Aplica el último valor tras una pausa al tipear. Al desmontar (popover que
 * se cierra antes de la pausa) aplica lo pendiente para no perder lo escrito.
 */
function useDebouncedApply<T>(apply: (value: T) => void) {
  const applyRef = useRef(apply)
  const pendingRef = useRef<{ value: T } | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  useEffect(() => {
    applyRef.current = apply
  }, [apply])

  const flush = useCallback(() => {
    clearTimeout(timerRef.current)
    const pending = pendingRef.current
    pendingRef.current = null
    if (pending) applyRef.current(pending.value)
  }, [])

  const schedule = useCallback(
    (value: T) => {
      pendingRef.current = { value }
      clearTimeout(timerRef.current)
      timerRef.current = setTimeout(flush, APPLY_DELAY_MS)
    },
    [flush],
  )

  useEffect(() => flush, [flush])

  return { schedule, flush }
}

function operatorLabel(field: ContactField, t: Translate): string {
  const kind = filterKindForField(field)
  if (kind === "any") {
    return t(`contactsPage.filters.custom.operators.${field.type === "multi_select" ? "anyMulti" : "any"}`)
  }
  return kind ? t(`contactsPage.filters.custom.operators.${kind}`) : ""
}

interface EditorProps<F extends CustomFieldFilter> {
  field: ContactField
  value: F | undefined
  onChange: (filter: CustomFieldFilter | undefined) => void
}

function AnyEditor({ field, value, onChange }: EditorProps<Extract<CustomFieldFilter, { kind: "any" }>>) {
  const { t } = useTranslation()
  const [query, setQuery] = useState("")
  const choices = useMemo(() => field.options?.choices ?? [], [field.options?.choices])
  const selected = new Set(value?.values ?? [])
  const normalizedQuery = query.trim().toLowerCase()
  const visibleChoices = normalizedQuery
    ? choices.filter((choice) => formatCustomChoice(choice).toLowerCase().includes(normalizedQuery))
    : choices

  const toggle = (choice: string, checked: boolean) => {
    // Respeta el orden de las opciones del campo, no el orden de los clics.
    const values = choices.filter((item) => (item === choice ? checked : selected.has(item)))
    onChange({ kind: "any", values })
  }

  return (
    <div className="space-y-1">
      {choices.length > CHOICE_SEARCH_THRESHOLD && (
        <Input
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={t("contactsPage.filters.custom.searchChoices")}
          aria-label={t("contactsPage.filters.custom.searchChoices")}
          className="mb-1 h-8"
        />
      )}
      <div className="max-h-64 overflow-y-auto" role="group" aria-label={field.label}>
        {visibleChoices.map((choice) => {
          const id = `custom-filter-${field.key}-${choice}`
          return (
            <label
              key={choice}
              htmlFor={id}
              className="flex min-h-8 cursor-pointer items-center gap-2.5 rounded-sm px-2 text-sm hover:bg-accent pointer-coarse:min-h-11"
            >
              <Checkbox
                id={id}
                checked={selected.has(choice)}
                onCheckedChange={(checked) => toggle(choice, checked === true)}
              />
              <span className="truncate">{formatCustomChoice(choice)}</span>
            </label>
          )
        })}
        {visibleChoices.length === 0 && (
          <p className="px-2 py-3 text-sm text-muted-foreground">{t("contactsPage.filters.custom.noChoices")}</p>
        )}
      </div>
    </div>
  )
}

function BooleanEditor({ field, value, onChange }: EditorProps<Extract<CustomFieldFilter, { kind: "boolean" }>>) {
  const { t } = useTranslation()
  return (
    <div className="space-y-2">
      <ToggleGroup
        type="single"
        variant="outline"
        value={value ? String(value.value) : ""}
        onValueChange={(next) => onChange(next ? { kind: "boolean", value: next === "true" } : undefined)}
        className="w-full gap-1.5"
        aria-label={field.label}
      >
        <ToggleGroupItem value="true" size="sm" className="flex-1 pointer-coarse:h-11">
          {t("contactsPage.filters.custom.yes")}
        </ToggleGroupItem>
        <ToggleGroupItem value="false" size="sm" className="flex-1 pointer-coarse:h-11">
          {t("contactsPage.filters.custom.no")}
        </ToggleGroupItem>
      </ToggleGroup>
      <p className="text-xs text-muted-foreground">{t("contactsPage.filters.custom.noIncludesEmpty")}</p>
    </div>
  )
}

function ContainsEditor({ field, value, onChange }: EditorProps<Extract<CustomFieldFilter, { kind: "contains" }>>) {
  const { t } = useTranslation()
  const [text, setText] = useState(value?.text ?? "")
  const applyText = useCallback(
    (next: string) => onChange(next.trim() ? { kind: "contains", text: next } : undefined),
    [onChange],
  )
  const { schedule, flush } = useDebouncedApply(applyText)

  return (
    <Input
      autoFocus
      value={text}
      onChange={(event) => {
        setText(event.target.value)
        schedule(event.target.value)
      }}
      onKeyDown={(event) => {
        if (event.key === "Enter") flush()
      }}
      placeholder={t("contactsPage.filters.custom.containsPlaceholder")}
      aria-label={field.label}
      className="h-9 pointer-coarse:h-11 pointer-coarse:text-base"
    />
  )
}

function isRangeInverted(field: ContactField, from: string, to: string): boolean {
  if (!from || !to) return false
  if (field.type === "date") return from > to
  return Number(from) > Number(to)
}

function RangeEditor({ field, value, onChange }: EditorProps<Extract<CustomFieldFilter, { kind: "range" }>>) {
  const { t } = useTranslation()
  const [from, setFrom] = useState(value?.from ?? "")
  const [to, setTo] = useState(value?.to ?? "")
  const inverted = isRangeInverted(field, from, to)
  const applyRange = useCallback(
    (range: { from: string; to: string }) =>
      onChange({ kind: "range", from: range.from || undefined, to: range.to || undefined }),
    [onChange],
  )
  const { schedule, flush } = useDebouncedApply(applyRange)
  const inputType = field.type === "date" ? "date" : "number"
  const errorId = `custom-filter-${field.key}-range-error`

  const update = (nextFrom: string, nextTo: string) => {
    setFrom(nextFrom)
    setTo(nextTo)
    // Un rango invertido no devuelve nada: se espera a que lo corrijan.
    if (!isRangeInverted(field, nextFrom, nextTo)) schedule({ from: nextFrom, to: nextTo })
  }

  const renderInput = (bound: "from" | "to") => {
    const id = `custom-filter-${field.key}-${bound}`
    return (
      <div className="space-y-1">
        <label htmlFor={id} className="text-xs text-muted-foreground">
          {t(`contactsPage.filters.custom.${bound}`)}
        </label>
        <Input
          id={id}
          type={inputType}
          inputMode={inputType === "number" ? "decimal" : undefined}
          value={bound === "from" ? from : to}
          onChange={(event) =>
            bound === "from" ? update(event.target.value, to) : update(from, event.target.value)
          }
          onKeyDown={(event) => {
            if (event.key === "Enter") flush()
          }}
          aria-invalid={inverted || undefined}
          aria-describedby={inverted ? errorId : undefined}
          className="h-9 pointer-coarse:h-11 pointer-coarse:text-base"
        />
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <div className="grid grid-cols-2 gap-2">
        {renderInput("from")}
        {renderInput("to")}
      </div>
      {inverted && (
        <p id={errorId} role="alert" className="text-xs text-destructive">
          {t("contactsPage.filters.custom.invalidRange")}
        </p>
      )}
    </div>
  )
}

interface CustomFieldFilterEditorProps {
  field: ContactField
  value: CustomFieldFilter | undefined
  onChange: (filter: CustomFieldFilter | undefined) => void
  /** Presente cuando el editor vive dentro de la lista de campos. */
  onBack?: () => void
}

export function CustomFieldFilterEditor({ field, value, onChange, onBack }: CustomFieldFilterEditorProps) {
  const { t } = useTranslation()
  const kind = filterKindForField(field)

  const renderBody = () => {
    switch (kind) {
      case "any":
        return <AnyEditor field={field} value={value?.kind === "any" ? value : undefined} onChange={onChange} />
      case "boolean":
        return <BooleanEditor field={field} value={value?.kind === "boolean" ? value : undefined} onChange={onChange} />
      case "contains":
        return <ContainsEditor field={field} value={value?.kind === "contains" ? value : undefined} onChange={onChange} />
      case "range":
        return <RangeEditor field={field} value={value?.kind === "range" ? value : undefined} onChange={onChange} />
      default:
        return null
    }
  }

  return (
    <div className="flex flex-col">
      <div className="flex min-h-10 items-center gap-1 border-b px-2">
        {onBack && (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-7 text-muted-foreground pointer-coarse:size-10"
            onClick={onBack}
            aria-label={t("contactsPage.filters.custom.back")}
          >
            <ArrowLeft className="size-4" aria-hidden="true" />
          </Button>
        )}
        <span className={onBack ? "truncate text-sm font-medium" : "truncate pl-1 text-sm font-medium"}>{field.label}</span>
        <span className="ml-auto shrink-0 pr-1 text-xs text-muted-foreground">{operatorLabel(field, t)}</span>
      </div>
      <div className="p-2">{renderBody()}</div>
      {value && (
        <div className="border-t p-1">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="w-full justify-start text-muted-foreground pointer-coarse:h-11"
            onClick={() => onChange(undefined)}
          >
            {t("contactsPage.filters.custom.remove")}
          </Button>
        </div>
      )}
    </div>
  )
}

interface FilterSurfaceProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  trigger: React.ReactNode
  /** Título accesible; en teléfono también se ve como encabezado de la hoja. */
  title: string
  children: React.ReactNode
  align?: "start" | "center" | "end"
  onEscapeKeyDown?: (event: KeyboardEvent) => void
}

/** Popover anclado al disparador; en teléfono, hoja inferior con targets táctiles. */
export function FilterSurface({ open, onOpenChange, trigger, title, children, align = "start", onEscapeKeyDown }: FilterSurfaceProps) {
  const isMobile = useIsMobile()

  if (isMobile) {
    return (
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetTrigger asChild>{trigger}</SheetTrigger>
        <SheetContent side="bottom" className="gap-0" onEscapeKeyDown={onEscapeKeyDown}>
          <SheetHeader className="pb-2">
            <SheetTitle>{title}</SheetTitle>
          </SheetHeader>
          <div className="px-2 pb-4">{children}</div>
        </SheetContent>
      </Sheet>
    )
  }

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>{trigger}</PopoverTrigger>
      <PopoverContent
        align={align}
        className="w-72 p-0"
        aria-label={title}
        onEscapeKeyDown={onEscapeKeyDown}
      >
        {children}
      </PopoverContent>
    </Popover>
  )
}

function formatDateValue(value: string, language: Language): string {
  const date = parseISO(value)
  if (!isValid(date)) return value
  const pattern = date.getFullYear() === new Date().getFullYear() ? "d MMM" : "d MMM yyyy"
  return format(date, pattern, { locale: language === "en" ? enUS : es })
}

function formatRangeValue(value: string, field: ContactField, language: Language): string {
  if (field.type === "date") return formatDateValue(value, language)
  if (field.type === "currency") return formatCurrency(value, field.options?.currency)
  const amount = Number(value)
  return Number.isFinite(amount) ? new Intl.NumberFormat(language === "en" ? "en-US" : "es-AR").format(amount) : value
}

/** Texto corto del valor de un filtro para su chip ("Pro, Enterprise", "≥ 1.000"). */
export function summarizeCustomFilter(filter: CustomFieldFilter, field: ContactField, t: Translate, language: Language): string {
  switch (filter.kind) {
    case "any": {
      const labels = filter.values.map(formatCustomChoice)
      if (labels.length <= 2) return labels.join(", ")
      return t("contactsPage.filters.custom.summary.more", { first: labels[0], count: labels.length - 1 })
    }
    case "contains":
      return t("contactsPage.filters.custom.summary.contains", { text: filter.text.trim() })
    case "boolean":
      return t(`contactsPage.filters.custom.${filter.value ? "yes" : "no"}`)
    case "range": {
      const from = filter.from ? formatRangeValue(filter.from, field, language) : null
      const to = filter.to ? formatRangeValue(filter.to, field, language) : null
      if (from && to) return t("contactsPage.filters.custom.summary.between", { from, to })
      if (from) return t("contactsPage.filters.custom.summary.atLeast", { value: from })
      return t("contactsPage.filters.custom.summary.atMost", { value: to ?? "" })
    }
  }
}
