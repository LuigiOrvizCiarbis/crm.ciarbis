"use client"

import Link from "next/link"
import type { ReactNode } from "react"
import { TrendingDown, TrendingUp } from "lucide-react"
import { StatStrip, type Stat } from "@/components/ui/stat-strip"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardEfficiency } from "@/lib/api/dashboard"
import { cn } from "@/lib/utils"
import { formatDays, formatMinutes } from "@/components/dashboard/duration"

interface TeamEfficiencyProps {
  efficiency?: DashboardEfficiency
  isLoading: boolean
  /** Muestra el acceso para definir metas a quien puede editarlas. */
  canEditGoals: boolean
}

const decimal = new Intl.NumberFormat("es-AR", { maximumFractionDigits: 1 })

/**
 * Variación contra el período anterior. En tiempos, bajar es mejorar: el color sigue al resultado,
 * no a la dirección. La flecha y el signo cargan la dirección por sí solos.
 */
function Change({ current, previous, lowerIsBetter }: { current: number; previous: number | null; lowerIsBetter: boolean }) {
  const { t } = useTranslation()
  if (previous === null || previous === 0) return null
  const change = ((current - previous) / previous) * 100
  if (Math.abs(change) < 0.5) return <span>{t("dashboard.efficiency.noChange")}</span>
  const improved = lowerIsBetter ? change < 0 : change > 0
  const Icon = change < 0 ? TrendingDown : TrendingUp
  return (
    <span className={cn("inline-flex items-center gap-0.5 font-medium tabular-nums", improved ? "text-success" : "text-destructive")}>
      <Icon className="size-3" aria-hidden="true" />
      {change > 0 ? "+" : ""}
      {decimal.format(change)}%
      <span className="sr-only"> {t(improved ? "dashboard.efficiency.better" : "dashboard.efficiency.worse")}</span>
    </span>
  )
}

function Detail({ parts }: { parts: ReactNode[] }) {
  const visible = parts.filter(Boolean)
  return (
    <span className="flex flex-wrap items-center gap-x-1.5">
      {visible.map((part, index) => (
        <span key={index} className="inline-flex items-center gap-1.5">
          {index > 0 && <span aria-hidden="true">·</span>}
          {part}
        </span>
      ))}
    </span>
  )
}

export function TeamEfficiency({ efficiency, isLoading, canEditGoals }: TeamEfficiencyProps) {
  const { t } = useTranslation()
  const loading = isLoading && !efficiency
  const response = efficiency?.response_time
  const cycle = efficiency?.cycle_time
  const tasks = efficiency?.tasks
  const missingGoals = Boolean(efficiency) && (response?.goal_minutes == null || cycle?.goal_days == null)

  // Sobre la meta (más lento de lo esperado) el número se marca en ámbar; el texto "Meta" lo explica.
  const overGoal = (value: number | null | undefined, goal: number | null | undefined) =>
    value != null && goal != null && value > goal

  const goalText = (formatted: string | null) => (formatted ? t("dashboard.efficiency.goal", { value: formatted }) : null)

  const stats: Stat[] = [
    {
      label: t("dashboard.efficiency.responseTime"),
      value: response?.avg_minutes != null ? (
        <span className={cn(overGoal(response.avg_minutes, response.goal_minutes) && "text-warning")}>{formatMinutes(t, response.avg_minutes)}</span>
      ) : "—",
      detail: response?.avg_minutes != null ? (
        <Detail
          parts={[
            goalText(response.goal_minutes != null ? formatMinutes(t, response.goal_minutes) : null),
            <Change key="c" current={response.avg_minutes} previous={response.previous_avg_minutes} lowerIsBetter />,
          ]}
        />
      ) : t("dashboard.efficiency.noReplies"),
    },
    {
      label: t("dashboard.efficiency.cycleTime"),
      value: cycle?.avg_days != null ? (
        <span className={cn(overGoal(cycle.avg_days, cycle.goal_days) && "text-warning")}>{formatDays(t, cycle.avg_days)}</span>
      ) : "—",
      detail: cycle?.avg_days != null ? (
        <Detail
          parts={[
            goalText(cycle.goal_days != null ? formatDays(t, cycle.goal_days) : null),
            <Change key="c" current={cycle.avg_days} previous={cycle.previous_avg_days} lowerIsBetter />,
          ]}
        />
      ) : t("dashboard.efficiency.noWins"),
    },
    {
      label: t("dashboard.efficiency.tasks"),
      value: tasks && tasks.total > 0 ? (
        <span>
          {tasks.completed.toLocaleString("es-AR")}
          <span className="text-base font-semibold text-muted-foreground md:text-lg">/{tasks.total.toLocaleString("es-AR")}</span>
        </span>
      ) : "—",
      detail: tasks && tasks.total > 0 ? (
        <span className="block">
          <span className="flex items-center justify-between gap-2">
            <span className="tabular-nums">{t("dashboard.efficiency.tasksRate", { value: Math.round((tasks.completed / tasks.total) * 100) })}</span>
            {tasks.previous_total > 0 && (
              <Change current={tasks.completed / tasks.total} previous={tasks.previous_completed / tasks.previous_total} lowerIsBetter={false} />
            )}
          </span>
          <span
            role="progressbar"
            aria-label={t("dashboard.efficiency.tasks")}
            aria-valuemin={0}
            aria-valuemax={tasks.total}
            aria-valuenow={tasks.completed}
            className="mt-2 block h-1.5 overflow-hidden rounded-full bg-muted"
          >
            <span className="block h-full rounded-full bg-chart-1" style={{ width: `${(tasks.completed / tasks.total) * 100}%` }} />
          </span>
        </span>
      ) : t("dashboard.efficiency.noTasks"),
    },
  ]

  return (
    <section aria-labelledby="panel-efficiency-title" aria-busy={isLoading || undefined}>
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="panel-efficiency-title" className="text-base font-semibold tracking-tight">{t("dashboard.efficiency.title")}</h2>
        {canEditGoals && missingGoals && (
          <Link
            href="/configuracion#panel-goals"
            className="rounded-sm text-sm font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
          >
            {t("dashboard.efficiency.setGoals")}
          </Link>
        )}
      </div>
      <StatStrip stats={stats} loading={loading} gridClassName="grid-cols-1 sm:grid-cols-3" />
    </section>
  )
}
