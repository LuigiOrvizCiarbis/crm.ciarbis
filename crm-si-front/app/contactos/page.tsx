"use client"

import { Suspense, useCallback, useEffect, useMemo, useState } from "react"
import { useSearchParams } from "next/navigation"
import { Search, X } from "lucide-react"
import { ContactsCompactHeader } from "@/components/contacts-compact-header"
import { ContactsStats } from "@/components/contacts-stats"
import { ContactsList } from "@/components/contacts-list"
import { SidebarLayout } from "@/components/SidebarLayout"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useIsMobile } from "@/hooks/use-mobile"
import { useTranslation } from "@/hooks/useTranslation"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"
import { ContactsMobileFilters } from "@/components/contacts/ContactsMobileFilters"
import { CustomFieldFilterMenu } from "@/components/contacts/CustomFieldFilterMenu"
import { StageFilterMenu } from "@/components/chat/StageFilterMenu"
import { useContactStageCounts } from "@/components/contacts/useContactStageCounts"
import {
  contactFiltersFromParams,
  contactFiltersToParams,
  isContactFilterParam,
  pruneCustomFilters,
  type ContactFilters,
} from "@/components/contacts/contactFilters"
import { BrandPilot } from "@/components/brand/BrandPilot"
import ContactosLoading from "./loading"

/**
 * Refleja los filtros en la URL (sobreviven a recargar y se pueden compartir)
 * sin sumar entradas al historial ni pisar parámetros ajenos a los filtros.
 */
function syncFiltersToUrl(filters: ContactFilters): void {
  const params = new URLSearchParams(window.location.search)
  for (const name of Array.from(params.keys())) {
    if (isContactFilterParam(name)) params.delete(name)
  }
  contactFiltersToParams(filters).forEach((value, name) => params.append(name, value))

  const query = params.toString()
  const nextUrl = `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`
  const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`
  if (nextUrl !== currentUrl) window.history.replaceState(null, "", nextUrl)
}

function ContactosView() {
  const { t } = useTranslation()
  const searchParams = useSearchParams()
  const [refreshKey, setRefreshKey] = useState(0)
  const [storedFilters, setFilters] = useState<ContactFilters>(() => contactFiltersFromParams(searchParams))
  const [billingAvailable, setBillingAvailable] = useState(false)
  const contactFields = useContactFieldsStore((s) => s.fields)
  const contactFieldsLoaded = useContactFieldsStore((s) => s.loaded)
  const isMobile = useIsMobile()
  const [stageMenuOpen, setStageMenuOpen] = useState(false)
  const [listTotal, setListTotal] = useState<number | undefined>(undefined)

  // Una URL vieja o compartida puede nombrar campos que ya no existen: se
  // ignoran al leer, así ni el fetch ni los chips ni la URL los arrastran.
  const filters = useMemo(() => {
    if (!contactFieldsLoaded) return storedFilters
    const custom = pruneCustomFilters(storedFilters.custom, contactFields)
    return custom === storedFilters.custom ? storedFilters : { ...storedFilters, custom }
  }, [storedFilters, contactFieldsLoaded, contactFields])

  const stageCounts = useContactStageCounts(filters, stageMenuOpen, refreshKey)

  useEffect(() => {
    syncFiltersToUrl(filters)
  }, [filters])

  const patchFilters = useCallback((patch: Partial<ContactFilters>) => {
    setFilters((current) => ({ ...current, ...patch }))
  }, [])

  // Estable: ContactsStats lo tiene en las deps de su efecto de carga, y una
  // función inline dispararía un refetch del summary en cada render.
  const handleBillingAvailability = useCallback((available: boolean) => {
    setBillingAvailable(available)
    // Sin cobranzas el backend ignora `billing=clients`: el chip mentiría.
    if (!available) setFilters((current) => (current.audience === "clients" ? { ...current, audience: "all" } : current))
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
      <BrandPilot />
      <ContactsCompactHeader
        searchQuery={filters.search}
        onSearch={(search) => patchFilters({ search })}
        sourceFilter={filters.source}
        onSourceFilter={(source) => patchFilters({ source })}
        tagFilterSlugs={filters.tags}
        onTagFilter={(tags) => patchFilters({ tags })}
        stageFilterKeys={filters.stages}
        onStageFilter={(stages) => patchFilters({ stages })}
        stageCounts={stageCounts.counts}
        stageCountsLoading={stageCounts.loading}
        onStageMenuOpenChange={setStageMenuOpen}
        customFilters={filters.custom}
        onCustomFilters={(custom) => patchFilters({ custom })}
        audience={filters.audience}
        onAudienceChange={(audience) => patchFilters({ audience })}
        billingAvailable={billingAvailable}
        onExportCSV={handleExportCSV}
        onImportCSV={handleImportCSV}
        onNewContact={handleNewContact}
      />

      <div className="flex-1 overflow-y-auto">
        {/* El buscador del header se oculta bajo md: en teléfonos va arriba de todo, antes de las métricas, junto con "Campos". Bajo sm se suma el botón de filtros que el header esconde. */}
        <div className="flex items-center gap-2 px-4 pt-4 md:hidden">
          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <Input
              value={filters.search}
              onChange={(event) => patchFilters({ search: event.target.value })}
              placeholder={t("contactsPage.searchPlaceholder")}
              aria-label={t("contactsPage.searchLabel")}
              autoComplete="off"
              enterKeyHint="search"
              className="h-11 pl-9 pr-11 text-base"
            />
            {filters.search && (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute right-0.5 top-1/2 size-10 -translate-y-1/2 text-muted-foreground"
                onClick={() => patchFilters({ search: "" })}
                aria-label={t("contactsPage.clearSearch")}
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </Button>
            )}
          </div>
          <ContactsMobileFilters
            sourceFilter={filters.source}
            onSourceFilter={(source) => patchFilters({ source })}
            audience={filters.audience}
            onAudienceChange={(audience) => patchFilters({ audience })}
            billingAvailable={billingAvailable}
          />
          {isMobile && (
            <>
              <StageFilterMenu
                compact
                selected={filters.stages}
                onChange={(stages) => patchFilters({ stages })}
                counts={stageCounts.counts}
                countsLoading={stageCounts.loading}
                onOpenChange={setStageMenuOpen}
                noneLabel={t("contactsPage.stage.filterNone")}
                showSelection={false}
                resultCount={listTotal}
                resultsLabel={listTotal === undefined ? undefined : t(`contactsPage.stage.showResults_${listTotal === 1 ? "one" : "other"}`, { count: listTotal })}
              />
              <CustomFieldFilterMenu
                compact
                fields={contactFields}
                value={filters.custom}
                onChange={(custom) => patchFilters({ custom })}
              />
            </>
          )}
        </div>
        <div className="space-y-4 px-4 py-5 md:px-6 lg:px-8">
          <ContactsStats refreshKey={refreshKey} onBillingAvailabilityChange={handleBillingAvailability} />
          <ContactsList
            filters={filters}
            onFiltersChange={setFilters}
            onContactsChanged={handleContactsChanged}
            onTotalChange={setListTotal}
          />
        </div>
      </div>
    </SidebarLayout>
  )
}

// useSearchParams necesita un Suspense propio para no forzar el render del
// lado del cliente de toda la ruta.
export default function ContactosPage() {
  return (
    <Suspense fallback={<ContactosLoading />}>
      <ContactosView />
    </Suspense>
  )
}
