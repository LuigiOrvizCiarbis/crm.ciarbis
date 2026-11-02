"use client"

import { useEffect, useState } from "react"
import { Download, Plus, Search, Upload, Wallet } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { TagFilterMenu } from "@/components/tags/TagFilterMenu"
import { RangeFilterMenu, type RangeFilterValue } from "@/components/contacts/RangeFilterMenu"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import { useTranslation } from "@/hooks/useTranslation"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

export type ContactsAudience = "all" | "clients"

/** Orígenes filtrables; los usa el select del header y la hoja de filtros de teléfono. */
export const CONTACT_SOURCES = [
  { value: "whatsapp", label: "WhatsApp" },
  { value: "instagram", label: "Instagram" },
  { value: "facebook", label: "Facebook" },
  { value: "manual", label: "Manual" },
] as const

interface ContactsCompactHeaderProps {
  searchQuery?: string
  onSearch?: (query: string) => void
  sourceFilter?: string
  onSourceFilter?: (source: string) => void
  tagFilterSlugs?: string[]
  onTagFilter?: (slugs: string[]) => void
  customRangeFilter?: Record<string, RangeFilterValue>
  onCustomRangeFilter?: (value: Record<string, RangeFilterValue>) => void
  audience?: ContactsAudience
  onAudienceChange?: (audience: ContactsAudience) => void
  /** El control de audiencia solo tiene sentido si el tenant usa cobranzas. */
  billingAvailable?: boolean
  onExportCSV?: () => void
  onImportCSV?: () => void
  onNewContact?: () => void
}

export function ContactsCompactHeader({
  searchQuery: searchQueryProp,
  onSearch,
  sourceFilter = "all",
  onSourceFilter,
  tagFilterSlugs = [],
  onTagFilter,
  customRangeFilter = {},
  onCustomRangeFilter,
  audience = "all",
  onAudienceChange,
  billingAvailable = false,
  onExportCSV,
  onImportCSV,
  onNewContact,
}: ContactsCompactHeaderProps) {
  const contactFields = useContactFieldsStore((s) => s.fields)
  const contactFieldsLoaded = useContactFieldsStore((s) => s.loaded)
  const fetchContactFields = useContactFieldsStore((s) => s.fetch)
  useEffect(() => {
    if (!contactFieldsLoaded) fetchContactFields()
  }, [contactFieldsLoaded, fetchContactFields])
  const { t } = useTranslation()
  const [searchQueryInternal, setSearchQueryInternal] = useState("")
  const isSearchControlled = searchQueryProp !== undefined
  const searchQuery = isSearchControlled ? searchQueryProp : searchQueryInternal

  const handleSearchChange = (value: string): void => {
    if (!isSearchControlled) setSearchQueryInternal(value)
    onSearch?.(value)
  }

  return (
    <div className="sticky top-0 z-40 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 border-b border-border">
      {/* Tablet (md a lg): dos filas, título + acciones arriba y búsqueda + filtros abajo. Teléfono y desktop: una fila. */}
      <div className="h-[75px] px-4 md:px-6 lg:px-8 flex items-center justify-between gap-4 md:max-lg:h-auto md:max-lg:flex-wrap md:max-lg:gap-y-3 md:max-lg:py-3">
        <div className="flex-shrink-0">
          <h1 className="text-xl md:text-2xl font-bold tracking-tight">
            {t("contactsPage.title")}
          </h1>
        </div>

        <div className="flex-1 max-w-md hidden md:block md:max-lg:order-3 md:max-lg:max-w-none">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t("contactsPage.searchPlaceholder")}
              aria-label={t("contactsPage.searchLabel")}
              value={searchQuery}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="pl-9 h-9 md:max-lg:h-10"
            />
          </div>
        </div>

        {/* Corte de línea sólo en tablet. */}
        <div className="hidden basis-full md:max-lg:order-2 md:max-lg:block" aria-hidden="true" />

        {/* Bajo lg los dos grupos se reordenan como hijos directos (contents); desde lg forman la fila original. */}
        <div className="contents lg:flex lg:items-center lg:gap-2 lg:flex-shrink-0">
        <div className="flex items-center gap-2 flex-shrink-0 max-md:ml-auto md:max-lg:order-4 lg:contents">
          {billingAvailable && (
            <ToggleGroup
              type="single"
              value={audience}
              // ToggleGroup permite deseleccionar y devuelve "": se ignora
              // para que la vista nunca quede sin audiencia elegida.
              onValueChange={(value) => {
                if (value) onAudienceChange?.(value as ContactsAudience)
              }}
              className="hidden gap-1.5 sm:flex"
              aria-label={t("contactsPage.filters.audience.label")}
            >
              <ToggleGroupItem value="all" size="sm" className="px-3">
                {t("contactsPage.filters.audience.all")}
              </ToggleGroupItem>
              <ToggleGroupItem value="clients" size="sm" className="gap-1.5 px-3">
                <Wallet className="h-4 w-4" />
                {t("contactsPage.filters.audience.clients")}
              </ToggleGroupItem>
            </ToggleGroup>
          )}

          <Select value={sourceFilter} onValueChange={(value) => onSourceFilter?.(value)}>
            <SelectTrigger className="w-[140px] h-9 hidden sm:flex md:max-lg:h-10">
              <SelectValue placeholder={t("contactsPage.filters.status")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("contactsPage.filters.all")}</SelectItem>
              {CONTACT_SOURCES.map((source) => (
                <SelectItem key={source.value} value={source.value}>{source.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          <TagFilterMenu
            selectedSlugs={tagFilterSlugs}
            onChange={(slugs) => onTagFilter?.(slugs)}
          />

          <RangeFilterMenu
            fields={contactFields}
            value={customRangeFilter}
            onChange={(value) => onCustomRangeFilter?.(value)}
          />
        </div>

        {/* Con cobranzas, el filtro de audiencia suma ~170px: entre lg y 2xl los botones de CSV quedan en ícono para que entren la búsqueda y "Nuevo contacto". */}
        <div className="flex items-center gap-2 flex-shrink-0 max-md:-ml-2 md:max-lg:order-1 md:max-lg:ml-auto lg:contents">
          <Button
            variant="outline"
            size="sm"
            onClick={onImportCSV}
            className="hidden md:flex gap-2 h-9 bg-transparent md:max-lg:h-10"
            aria-label={t("contactsPage.actions.importCsv")}
            title={billingAvailable ? t("contactsPage.actions.importCsv") : undefined}
          >
            <Upload className="w-4 h-4" />
            <span className={billingAvailable ? "lg:max-2xl:hidden" : undefined}>{t("contactsPage.actions.importCsv")}</span>
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={onExportCSV}
            className="hidden md:flex gap-2 h-9 bg-transparent md:max-lg:h-10"
            aria-label={t("contactsPage.actions.exportCsv")}
            title={billingAvailable ? t("contactsPage.actions.exportCsv") : undefined}
          >
            <Download className="w-4 h-4" />
            <span className={billingAvailable ? "lg:max-2xl:hidden" : undefined}>{t("contactsPage.actions.exportCsv")}</span>
          </Button>

          <Button size="sm" onClick={onNewContact} className="h-9 max-sm:size-10 md:max-lg:h-10" aria-label={t("contactsPage.actions.newContact")}>
            <Plus className="w-4 h-4 sm:mr-1" />
            <span className="hidden sm:inline">{t("contactsPage.actions.newContact")}</span>
          </Button>
        </div>
        </div>
      </div>
    </div>
  )
}
