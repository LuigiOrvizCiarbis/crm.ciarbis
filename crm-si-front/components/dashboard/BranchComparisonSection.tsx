"use client"

import { useEffect, useState } from "react"
import { PanelSection } from "@/components/dashboard/PanelSection"
import { Button } from "@/components/ui/button"
import { DataTable, type DataTableColumn } from "@/components/ui/data-table"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import { useBranchesStore } from "@/store/useBranchesStore"
import { getBranchComparison, type BranchComparisonRow, type DashboardFilters } from "@/lib/api/dashboard"
import { formatCompactCurrency } from "@/lib/currency"

interface BranchComparisonSectionProps {
  /** Los filtros del encabezado salvo la sucursal: la tabla siempre muestra todas. */
  filters: DashboardFilters
  /** Sucursal elegida en el encabezado, para marcar su fila. */
  selectedBranchId: number | null
}

export function BranchComparisonSection({ filters, selectedBranchId }: BranchComparisonSectionProps) {
  const { t } = useTranslation()
  const isOwner = useIsOwner()
  const canViewAllPermission = usePermission("branches.view_all")
  const canManage = usePermission("branches.manage")
  // Los mismos permisos que exige el backend para la comparativa.
  const canViewAll = isOwner || canViewAllPermission || canManage
  const { branches, loaded, fetch } = useBranchesStore()
  const [stats, setStats] = useState<BranchComparisonRow[]>([])
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    if (!loaded) fetch()
  }, [loaded, fetch])

  useEffect(() => {
    if (!canViewAll) return
    if (loaded && branches.length === 0) return

    let cancelled = false
    setLoading(true)
    setFailed(false)
    getBranchComparison(filters)
      .then((rows) => { if (!cancelled) setStats(rows) })
      .catch(() => { if (!cancelled) setFailed(true) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => {
      cancelled = true
    }
  }, [filters, canViewAll, branches.length, loaded, reloadKey])

  // Sin sucursales la comparación no tiene sentido: la sección no aparece.
  if (!canViewAll) return null
  if (loaded && branches.length === 0) return null

  const numeric = "tabular-nums"
  const count = (value: number) => value.toLocaleString("es-AR")
  const columns: DataTableColumn<BranchComparisonRow>[] = [
    { id: "name", header: t("sucursales.fields.name"), rowHeader: true, cell: (row) => row.name },
    { id: "contacts", header: t("sucursales.dashboard.contacts"), align: "right", className: numeric, cell: (row) => count(row.contacts_new) },
    { id: "conversations", header: t("sucursales.dashboard.conversations"), align: "right", className: numeric, cell: (row) => count(row.conversations) },
    { id: "opportunities", header: t("sucursales.dashboard.opportunities"), align: "right", className: numeric, cell: (row) => count(row.opportunities_count) },
    { id: "value", header: t("sucursales.dashboard.value"), align: "right", className: numeric, cell: (row) => formatCompactCurrency(row.opportunities_value) },
    { id: "won", header: t("sucursales.dashboard.won"), align: "right", className: numeric, cell: (row) => count(row.opportunities_won) },
  ]

  return (
    <PanelSection
      id="panel-branches"
      title={t("sucursales.dashboard.title")}
      description={t("sucursales.dashboard.subtitle")}
      busy={loading}
    >
      {failed ? (
        <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
          {t("dashboard.errors.branchesFailed")}
          <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
            {t("dashboard.errors.retry")}
          </Button>
        </div>
      ) : loading && stats.length === 0 ? (
        <div className="space-y-3">
          {[0, 1, 2].map((row) => <Skeleton key={row} className="h-6 w-full" />)}
        </div>
      ) : (
        <DataTable
          className="-mx-3"
          variant="plain"
          density="compact"
          minTableWidth="36rem"
          columns={columns}
          data={stats}
          getRowId={(row) => String(row.id)}
          activeRowId={selectedBranchId != null ? String(selectedBranchId) : undefined}
        />
      )}
    </PanelSection>
  )
}
