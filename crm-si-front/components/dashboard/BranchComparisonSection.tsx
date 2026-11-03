"use client"

import { useEffect, useState } from "react"
import { PanelSection } from "@/components/dashboard/PanelSection"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import { useBranchesStore } from "@/store/useBranchesStore"
import { getBranchComparison, type BranchComparisonRow, type DashboardFilters } from "@/lib/api/dashboard"
import { formatCompactCurrency } from "@/lib/currency"
import { cn } from "@/lib/utils"

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

  const numeric = "px-3 py-2.5 text-right tabular-nums"

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
        <div className="-mx-3 overflow-x-auto">
          <table className="w-full min-w-[36rem] text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-muted-foreground">
                <th scope="col" className="px-3 py-2 font-medium">{t("sucursales.fields.name")}</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">{t("sucursales.dashboard.contacts")}</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">{t("sucursales.dashboard.conversations")}</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">{t("sucursales.dashboard.opportunities")}</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">{t("sucursales.dashboard.value")}</th>
                <th scope="col" className="px-3 py-2 text-right font-medium">{t("sucursales.dashboard.won")}</th>
              </tr>
            </thead>
            <tbody>
              {stats.map((row) => {
                const selected = row.id === selectedBranchId
                return (
                  <tr key={row.id} aria-current={selected ? "true" : undefined} className={cn("border-b border-border last:border-0", selected && "bg-muted")}>
                    <th scope="row" className={cn("px-3 py-2.5 text-left", selected ? "font-semibold" : "font-medium")}>{row.name}</th>
                    <td className={numeric}>{row.contacts_new.toLocaleString("es-AR")}</td>
                    <td className={numeric}>{row.conversations.toLocaleString("es-AR")}</td>
                    <td className={numeric}>{row.opportunities_count.toLocaleString("es-AR")}</td>
                    <td className={numeric}>{formatCompactCurrency(row.opportunities_value)}</td>
                    <td className={numeric}>{row.opportunities_won.toLocaleString("es-AR")}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </PanelSection>
  )
}
