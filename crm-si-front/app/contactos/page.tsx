"use client"

import { useCallback, useState } from "react"
import { ContactsCompactHeader, type ContactsAudience } from "@/components/contacts-compact-header"
import { ContactsStats } from "@/components/contacts-stats"
import { ContactsList } from "@/components/contacts-list"
import { SidebarLayout } from "@/components/SidebarLayout"
import type { RangeFilterValue } from "@/components/contacts/RangeFilterMenu"

export default function ContactosPage() {
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
        <div className="px-4 md:px-6 lg:px-8 py-6 space-y-6">
          <ContactsStats refreshKey={refreshKey} onBillingAvailabilityChange={handleBillingAvailability} />
          <ContactsList
            searchTerm={searchQuery}
            onSearchTermChange={setSearchQuery}
            sourceFilter={sourceFilter}
            tagFilterSlugs={tagFilterSlugs}
            customRangeFilter={customRangeFilter}
            audience={audience}
          />
        </div>
      </div>
    </SidebarLayout>
  )
}
