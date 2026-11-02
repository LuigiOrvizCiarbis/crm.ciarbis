"use client"

import { TrendingDown, TrendingUp } from "lucide-react"
import { StatStrip, type Stat } from "@/components/ui/stat-strip"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardKpis } from "@/lib/api/dashboard"

interface MetricsCardsProps {
  kpis?: DashboardKpis
  previous?: DashboardKpis
  trends?: Record<string, number>
  isLoading?: boolean
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("es-AR").format(Math.round(value))
}

function formatCurrency(value: number): string {
  if (value >= 1000) {
    return `$${(value / 1000).toFixed(value >= 10000 ? 0 : 1)}k`
  }
  return `$${formatNumber(value)}`
}

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

// Variación + referencia en una sola línea. La flecha y el signo cargan la
// dirección; el color la refuerza. Sin variación queda neutra.
function TrendLine({ trend, previousLabel }: { trend?: number; previousLabel: string }) {
  if (trend === undefined || trend === 0) return <>{previousLabel}</>
  const isPositive = trend > 0
  const Icon = isPositive ? TrendingUp : TrendingDown
  return (
    <>
      <span className={`inline-flex items-center gap-0.5 font-medium tabular-nums ${isPositive ? "text-success" : "text-destructive"}`}>
        <Icon className="size-3" aria-hidden="true" />
        {isPositive ? "+" : ""}
        {trend}%
      </span>{" "}
      {previousLabel}
    </>
  )
}

export function MetricsCards({ kpis, previous, trends, isLoading }: MetricsCardsProps) {
  const { t } = useTranslation()
  const loading = Boolean(isLoading || !kpis || !previous || !trends)

  const previousLabel = (rawValue: string): string => t("dashboard.kpis.vsPrevious").replace("{value}", rawValue)

  const metric = (
    label: string,
    format: (value: number) => string,
    pick: (source: DashboardKpis) => number,
    trend: (source: Record<string, number>) => number | undefined,
  ): Stat =>
    loading
      ? { label, value: null, detail: null }
      : {
          label,
          value: format(pick(kpis!)),
          detail: <TrendLine trend={trend(trends!)} previousLabel={previousLabel(format(pick(previous!)))} />,
        }

  const stats: Stat[] = [
    metric(t("dashboard.kpis.totalConversations"), formatNumber, (k) => k.total_conversations, (tr) => tr.total_conversations),
    metric(
      t("dashboard.kpis.totalRevenue"),
      formatCurrency,
      (k) => k.won_value || k.pipeline_value,
      (tr) => tr.won_value ?? tr.pipeline_value,
    ),
    metric(t("dashboard.kpis.globalConversion"), formatPercent, (k) => k.global_conversion_rate, (tr) => tr.global_conversion_rate),
    metric(t("dashboard.kpis.avgRoi"), formatPercent, (k) => k.avg_roi, (tr) => tr.avg_roi),
  ]

  return <StatStrip stats={stats} loading={loading} />
}
