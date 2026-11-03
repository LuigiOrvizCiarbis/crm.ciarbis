"use client"

import { useEffect, useMemo, useState } from "react"
import { useRouter } from "next/navigation"
import { useAuthStore } from "@/store/useAuthStore"
import { SidebarLayout } from "@/components/SidebarLayout"
import { BrandPilot } from "@/components/brand/BrandPilot"
import { ALL, DEFAULT_PIPELINE, DashboardCompactHeader } from "@/components/dashboard-compact-header"
import { MetricsCards } from "@/components/metrics-cards"
import { StalledStages } from "@/components/dashboard/StalledStages"
import { NewOpportunitiesTrend } from "@/components/dashboard/NewOpportunitiesTrend"
import { ChannelBreakdown } from "@/components/dashboard/ChannelBreakdown"
import { BranchComparisonSection } from "@/components/dashboard/BranchComparisonSection"
import { TeamEfficiency } from "@/components/dashboard/TeamEfficiency"
import { AiImpact } from "@/components/dashboard/AiImpact"
import { RevenueSection } from "@/components/dashboard/RevenueSection"
import { Button } from "@/components/ui/button"
import { useDashboardMetrics } from "@/hooks/useDashboardMetrics"
import { useTranslation } from "@/hooks/useTranslation"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import type { DashboardFilters, DashboardPeriodo } from "@/lib/api/dashboard"

export default function Dashboard() {
  const router = useRouter()
  const { isAuthenticated } = useAuthStore()
  const { t } = useTranslation()
  const canViewTeamAnalytics = usePermission("analytics.view_team")
  const isOwner = useIsOwner()
  const canManageStages = usePermission("pipeline_stages.manage")

  const [periodo, setPeriodo] = useState<DashboardPeriodo>("este-mes")
  const [canal, setCanal] = useState(ALL)
  const [owner, setOwner] = useState(ALL)
  const [pipeline, setPipeline] = useState(DEFAULT_PIPELINE)
  const [sucursal, setSucursal] = useState(ALL)

  useEffect(() => {
    if (!isAuthenticated) {
      router.replace("/login")
    }
  }, [isAuthenticated, router])

  // Sin sucursal: la comparativa no se vuelve a pedir al cambiarla, porque siempre muestra todas.
  const comparisonFilters = useMemo<DashboardFilters>(
    () => ({
      periodo,
      canal: canal === ALL ? null : canal,
      owner: owner === ALL ? null : owner,
      // "todos" y "default" los entiende el backend tal cual.
      pipeline,
    }),
    [periodo, canal, owner, pipeline]
  )
  const filters = useMemo<DashboardFilters>(
    () => ({ ...comparisonFilters, sucursal: sucursal === ALL ? null : sucursal }),
    [comparisonFilters, sucursal]
  )

  const { data, isLoading, error, refresh } = useDashboardMetrics(filters)

  if (!isAuthenticated) {
    return null
  }

  return (
    <SidebarLayout>
      <BrandPilot />
      <DashboardCompactHeader
        periodo={periodo}
        onPeriodoChange={setPeriodo}
        canal={canal}
        onCanalChange={setCanal}
        owner={owner}
        onOwnerChange={setOwner}
        pipeline={pipeline}
        onPipelineChange={setPipeline}
        sucursal={sucursal}
        onSucursalChange={setSucursal}
      />

      {/* Una sola vista que responde "cómo viene el negocio y dónde actuar". Los módulos
          dependen de la misma consulta de métricas; sucursales carga aparte. */}
      <div className="mx-auto w-full max-w-[1400px] space-y-6 px-4 py-6 md:px-6 lg:px-8 lg:py-8">
        {error ? (
          <div role="alert" className="flex flex-wrap items-center gap-3 rounded-xl border border-destructive/30 bg-destructive/10 px-5 py-4 text-sm">
            <span className="font-medium text-destructive">{t("dashboard.errors.loadFailed")}</span>
            <span className="text-foreground">{error}</span>
            <Button variant="outline" size="sm" className="ml-auto" onClick={refresh}>
              {t("dashboard.errors.retry")}
            </Button>
          </div>
        ) : (
          <>
            <MetricsCards
              kpis={data?.kpis}
              previous={data?.previous}
              trends={data?.trends as Record<string, number> | undefined}
              isLoading={isLoading}
            />
            <div className="grid items-start gap-6 xl:grid-cols-12">
              <StalledStages
                className="xl:col-span-7"
                stages={data?.stages}
                staleAfterDays={data?.stale_after_days}
                isLoading={isLoading}
              />
              <div className="space-y-6 xl:col-span-5">
                <NewOpportunitiesTrend series={data?.daily_series} isLoading={isLoading} />
                <ChannelBreakdown channels={data?.omnichannel} isLoading={isLoading} />
              </div>
            </div>
            <RevenueSection
              revenue={data?.revenue}
              isLoading={isLoading}
              goalApplies={periodo === "este-mes" || periodo === "este-trimestre" || periodo === "este-ano"}
              narrowed={canal !== ALL || owner !== ALL || sucursal !== ALL}
              collectionsIgnoreFilters={canal !== ALL || owner !== ALL || pipeline !== DEFAULT_PIPELINE}
              canSetGoal={isOwner || canViewTeamAnalytics}
              canSetProbabilities={isOwner || canManageStages}
            />
            <TeamEfficiency efficiency={data?.efficiency} isLoading={isLoading} canEditGoals={isOwner || canViewTeamAnalytics} />
            {/* El backend sólo manda el bloque a quien ve todo el equipo. */}
            {(data?.ai || (isLoading && (isOwner || canViewTeamAnalytics))) && (
              <AiImpact
                ai={data?.ai}
                isLoading={isLoading}
                ignoresFilters={owner !== ALL || pipeline !== DEFAULT_PIPELINE}
              />
            )}
          </>
        )}
        <BranchComparisonSection filters={comparisonFilters} selectedBranchId={sucursal === ALL ? null : Number(sucursal)} />
      </div>
    </SidebarLayout>
  )
}
