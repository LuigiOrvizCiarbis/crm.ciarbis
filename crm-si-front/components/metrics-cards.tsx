"use client"

import { TrendingDown, TrendingUp } from "lucide-react"
import { StatStrip, type Stat } from "@/components/ui/stat-strip"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardKpis } from "@/lib/api/dashboard"
import { formatCompactCurrency } from "@/lib/currency"

interface MetricsCardsProps {
  kpis?: DashboardKpis
  previous?: DashboardKpis
  trends?: Record<string, number>
  isLoading?: boolean
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("es-AR").format(Math.round(value))
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
    // Lo ganado por fecha de cierre; sin ventas en el período es $0, no el valor del embudo.
    metric(t("dashboard.kpis.totalRevenue"), formatCompactCurrency, (k) => k.won_value, (tr) => tr.won_value),
    metric(t("dashboard.kpis.globalConversion"), formatPercent, (k) => k.global_conversion_rate, (tr) => tr.global_conversion_rate),
    metric(t("dashboard.kpis.avgTicket"), formatCompactCurrency, (k) => k.avg_ticket, (tr) => tr.avg_ticket),
  ]

  return <StatStrip stats={stats} loading={loading} />
}
