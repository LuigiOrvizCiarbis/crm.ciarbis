"use client"

import { useCallback, useState } from "react"
import { Search, X } from "lucide-react"
import { ContactsCompactHeader, type ContactsAudience } from "@/components/contacts-compact-header"
import { ContactsStats } from "@/components/contacts-stats"
import { ContactsList } from "@/components/contacts-list"
import { SidebarLayout } from "@/components/SidebarLayout"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useTranslation } from "@/hooks/useTranslation"
import type { RangeFilterValue } from "@/components/contacts/RangeFilterMenu"
import { ContactsMobileFilters } from "@/components/contacts/ContactsMobileFilters"

export default function ContactosPage() {
  const { t } = useTranslation()
  const [refreshKey, setRefreshKey] = useState(0)
  const [searchQuery, setSearchQuery] = useState("")
  const [sourceFilter, setSourceFilter] = useState("all")
  const [tagFilterSlugs, setTagFilterSlugs] = useState<string[]>([])
  const [customRangeFilter, setCustomRangeFilter] = useState<Record<string, RangeFilterValue>>({})
  const [audience, setAudience] = useState<ContactsAudience>("all")
  const [billingAvailable, setBillingAvailable] = useState(false)

  // Estable: ContactsStats lo tiene en las deps de su efecto de carga, y una
  // función inline dispararía un refetch del summary en cada render.
  const handleBillingAvailability = useCallback((available: boolean) => {
    setBillingAvailable(available)
  }, [])

  // Las métricas se recalculan cuando la lista crea, edita, borra o importa contactos.
  const handleContactsChanged = useCallback(() => {
    setRefreshKey((key) => key + 1)
  }, [])

  const handleNewContact = (): void => {
    window.dispatchEvent(new CustomEvent("contacts-new-contact"))
  }

  const handleExportCSV = (): void => {
    window.dispatchEvent(new CustomEvent("contacts-export-csv"))
  }

  const handleImportCSV = (): void => {
    window.dispatchEvent(new CustomEvent("contacts-import-csv"))
  }

  return (
    <SidebarLayout>
      <ContactsCompactHeader
        searchQuery={searchQuery}
        onSearch={setSearchQuery}
        sourceFilter={sourceFilter}
        onSourceFilter={setSourceFilter}
        tagFilterSlugs={tagFilterSlugs}
        onTagFilter={setTagFilterSlugs}
        customRangeFilter={customRangeFilter}
        onCustomRangeFilter={setCustomRangeFilter}
        audience={audience}
        onAudienceChange={setAudience}
        billingAvailable={billingAvailable}
        onExportCSV={handleExportCSV}
        onImportCSV={handleImportCSV}
        onNewContact={handleNewContact}
      />

      <div className="flex-1 overflow-y-auto">
        {/* El buscador del header se oculta bajo md: en teléfonos va arriba de todo, antes de las métricas. Bajo sm se suma el botón de filtros que el header esconde. */}
        <div className="flex items-center gap-2 px-4 pt-4 md:hidden">
          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <Input
              value={searchQuery}
              onChange={(event) => setSearchQuery(event.target.value)}
              placeholder={t("contactsPage.searchPlaceholder")}
              aria-label={t("contactsPage.searchLabel")}
              autoComplete="off"
              enterKeyHint="search"
              className="h-11 pl-9 pr-11 text-base"
            />
            {searchQuery && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute right-0.5 top-1/2 size-10 -translate-y-1/2 text-muted-foreground"
                onClick={() => setSearchQuery("")}
                aria-label={t("contactsPage.clearSearch")}
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </Button>
            )}
          </div>
          <ContactsMobileFilters
            sourceFilter={sourceFilter}
            onSourceFilter={setSourceFilter}
            audience={audience}
            onAudienceChange={setAudience}
            billingAvailable={billingAvailable}
          />
        </div>
        <div className="px-4 md:px-6 lg:px-8 py-6 space-y-6">
          <ContactsStats refreshKey={refreshKey} onBillingAvailabilityChange={handleBillingAvailability} />
          <ContactsList
            searchTerm={searchQuery}
            onSearchTermChange={setSearchQuery}
            sourceFilter={sourceFilter}
            tagFilterSlugs={tagFilterSlugs}
            customRangeFilter={customRangeFilter}
            audience={audience}
            onContactsChanged={handleContactsChanged}
          />
        </div>
      </div>
    </SidebarLayout>
  )
}
