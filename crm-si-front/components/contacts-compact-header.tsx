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
    <div className="sticky top-0 z-40 border-b border-border bg-background">
      {/* Tablet y laptop (md a xl): dos filas, título + acciones arriba y búsqueda + filtros abajo. Con el sidebar abierto,
          en una sola fila el buscador quedaba de 50px. Teléfono y desktop ancho: una fila. */}
      <div className="h-[75px] px-4 md:px-6 lg:px-8 flex items-center justify-between gap-4 md:max-xl:h-auto md:max-xl:flex-wrap md:max-xl:gap-y-3 md:max-xl:py-3">
        <div className="flex-shrink-0">
          <h1 className="text-xl md:text-2xl font-bold tracking-tight">
            {t("contactsPage.title")}
          </h1>
        </div>

        <div className="flex-1 max-w-md hidden md:block md:max-xl:order-3 md:max-xl:max-w-none">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t("contactsPage.searchPlaceholder")}
              aria-label={t("contactsPage.searchLabel")}
              value={searchQuery}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="pl-9 h-9 md:max-xl:h-10"
            />
          </div>
        </div>

        {/* Corte de línea sólo bajo xl. */}
        <div className="hidden basis-full md:max-xl:order-2 md:max-xl:block" aria-hidden="true" />

        {/* Bajo xl los dos grupos se reordenan como hijos directos (contents); desde xl forman la fila original. */}
        <div className="contents xl:flex xl:items-center xl:gap-2 xl:flex-shrink-0">
        <div className="flex items-center gap-2 flex-shrink-0 max-md:ml-auto md:max-xl:order-4 xl:contents">
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
            <SelectTrigger className="h-9 w-auto min-w-[140px] gap-2 hidden sm:flex md:max-xl:h-10" aria-label={t("contactsPage.filters.source")}>
              <SelectValue placeholder={t("contactsPage.filters.source")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("contactsPage.filters.allSources")}</SelectItem>
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

        {/* En una fila (xl+), los botones de CSV quedan en ícono hasta 1400px (2xl con el filtro de audiencia de cobranzas)
            para que entren la búsqueda y "Nuevo contacto". CSV va en ghost: es de vez en cuando y no compite con "Nuevo contacto". */}
        <div className="flex items-center gap-2 flex-shrink-0 max-md:-ml-2 md:max-xl:order-1 md:max-xl:ml-auto xl:contents">
          <Button
            variant="ghost"
            size="sm"
            onClick={onImportCSV}
            className="hidden md:flex gap-2 h-9 md:max-xl:h-10"
            aria-label={t("contactsPage.actions.importCsv")}
            title={t("contactsPage.actions.importCsv")}
          >
            <Upload className="w-4 h-4" />
            <span className={billingAvailable ? "xl:max-2xl:hidden" : "xl:max-[1400px]:hidden"}>{t("contactsPage.actions.importCsv")}</span>
          </Button>

          <Button
            variant="ghost"
            size="sm"
            onClick={onExportCSV}
            className="hidden md:flex gap-2 h-9 md:max-xl:h-10"
            aria-label={t("contactsPage.actions.exportCsv")}
            title={t("contactsPage.actions.exportCsv")}
          >
            <Download className="w-4 h-4" />
            <span className={billingAvailable ? "xl:max-2xl:hidden" : "xl:max-[1400px]:hidden"}>{t("contactsPage.actions.exportCsv")}</span>
          </Button>

          <Button size="sm" onClick={onNewContact} className="h-9 max-sm:size-10 md:max-xl:h-10" aria-label={t("contactsPage.actions.newContact")}>
            <Plus className="w-4 h-4 sm:mr-1" />
            <span className="hidden sm:inline">{t("contactsPage.actions.newContact")}</span>
          </Button>
        </div>
        </div>
      </div>
    </div>
  )
}
