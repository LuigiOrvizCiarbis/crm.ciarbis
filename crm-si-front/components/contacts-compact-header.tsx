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
      <div className="h-[75px] px-4 md:px-6 lg:px-8 flex items-center justify-between gap-4">
        <div className="flex-shrink-0">
          <h1 className="text-xl md:text-2xl font-bold tracking-tight">
            {t("contactsPage.title")}
          </h1>
        </div>

        <div className="flex-1 max-w-md hidden md:block">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t("contactsPage.searchPlaceholder")}
              value={searchQuery}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="pl-9 h-9"
            />
          </div>
        </div>

        <div className="flex items-center gap-2 flex-shrink-0">
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
            <SelectTrigger className="w-[140px] h-9 hidden sm:flex">
              <SelectValue placeholder={t("contactsPage.filters.status")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("contactsPage.filters.all")}</SelectItem>
              <SelectItem value="whatsapp">WhatsApp</SelectItem>
              <SelectItem value="instagram">Instagram</SelectItem>
              <SelectItem value="facebook">Facebook</SelectItem>
              <SelectItem value="manual">Manual</SelectItem>
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

          <Button
            variant="outline"
            size="sm"
            onClick={onImportCSV}
            className="hidden md:flex gap-2 h-9 bg-transparent"
          >
            <Upload className="w-4 h-4" />
            {t("contactsPage.actions.importCsv")}
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={onExportCSV}
            className="hidden md:flex gap-2 h-9 bg-transparent"
          >
            <Download className="w-4 h-4" />
            {t("contactsPage.actions.exportCsv")}
          </Button>

          <Button size="sm" onClick={onNewContact} className="h-9">
            <Plus className="w-4 h-4 mr-1" />
            <span className="hidden sm:inline">{t("contactsPage.actions.newContact")}</span>
          </Button>
        </div>
      </div>
    </div>
  )
}
