"use client"

import { useState } from "react"
import { Check, SlidersHorizontal } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { CONTACT_SOURCES, type ContactsAudience } from "@/components/contacts-compact-header"
import { useTranslation } from "@/hooks/useTranslation"

interface ContactsMobileFiltersProps {
  sourceFilter: string
  onSourceFilter: (source: string) => void
  audience: ContactsAudience
  onAudienceChange: (audience: ContactsAudience) => void
  /** Igual que en el header: el filtro de audiencia sólo existe si el tenant usa cobranzas. */
  billingAvailable: boolean
}

interface OptionGroupProps {
  name: string
  legend: string
  value: string
  options: ReadonlyArray<{ value: string; label: string }>
  onChange: (value: string) => void
}

function OptionGroup({ name, legend, value, options, onChange }: OptionGroupProps) {
  return (
    <fieldset className="space-y-1">
      <legend className="mb-2 text-sm font-medium text-muted-foreground">{legend}</legend>
      {options.map((option) => {
        const checked = option.value === value
        return (
          <label
            key={option.value}
            className="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-transparent px-3 text-base transition-colors hover:bg-accent has-[:checked]:border-primary has-[:checked]:font-semibold has-[:checked]:text-primary has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
          >
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={checked}
              onChange={() => onChange(option.value)}
              className="sr-only"
            />
            <span className="flex-1">{option.label}</span>
            {checked && <Check className="size-4 shrink-0" aria-hidden="true" />}
          </label>
        )
      })}
    </fieldset>
  )
}

/** Teléfonos (<640px): el header oculta origen y audiencia, así que se exponen en una hoja inferior. */
export function ContactsMobileFilters({
  sourceFilter,
  onSourceFilter,
  audience,
  onAudienceChange,
  billingAvailable,
}: ContactsMobileFiltersProps) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const activeCount = Number(sourceFilter !== "all") + Number(billingAvailable && audience !== "all")

  return (
    <>
      <Button
        type="button"
        variant="outline"
        className="relative size-11 shrink-0 bg-transparent sm:hidden"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        aria-label={activeCount > 0 ? t("contactsPage.filters.buttonActive", { count: activeCount }) : t("contactsPage.filters.button")}
      >
        <SlidersHorizontal className="size-4" aria-hidden="true" />
        {activeCount > 0 && (
          <span
            className="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-medium tabular-nums text-primary-foreground"
            aria-hidden="true"
          >
            {activeCount}
          </span>
        )}
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="bottom">
          <SheetHeader>
            <SheetTitle>{t("contactsPage.filters.title")}</SheetTitle>
            <SheetDescription>{t("contactsPage.filters.description")}</SheetDescription>
          </SheetHeader>

          <div className="space-y-6 px-4">
            <OptionGroup
              name="contacts-source"
              legend={t("contactsPage.filters.source")}
              value={sourceFilter}
              options={[{ value: "all", label: t("contactsPage.filters.all") }, ...CONTACT_SOURCES]}
              onChange={onSourceFilter}
            />
            {billingAvailable && (
              <OptionGroup
                name="contacts-audience"
                legend={t("contactsPage.filters.audience.label")}
                value={audience}
                options={[
                  { value: "all", label: t("contactsPage.filters.audience.all") },
                  { value: "clients", label: t("contactsPage.filters.audience.clients") },
                ]}
                onChange={(value) => onAudienceChange(value as ContactsAudience)}
              />
            )}
          </div>

          <SheetFooter className="flex-row gap-2">
            <Button
              type="button"
              variant="outline"
              className="h-11 flex-1 text-base"
              disabled={activeCount === 0}
              onClick={() => {
                onSourceFilter("all")
                onAudienceChange("all")
              }}
            >
              {t("contactsPage.filters.clear")}
            </Button>
            <Button type="button" className="h-11 flex-1 text-base" onClick={() => setOpen(false)}>
              {t("contactsPage.filters.done")}
            </Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </>
  )
}
