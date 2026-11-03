"use client"

import { PanelSection } from "@/components/dashboard/PanelSection"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardDailyEntry } from "@/lib/api/dashboard"

interface NewOpportunitiesTrendProps {
  series?: DashboardDailyEntry[]
  isLoading: boolean
  className?: string
}

const dayFormat = new Intl.DateTimeFormat("es-AR", { day: "numeric", month: "short", timeZone: "UTC" })

/** La serie trae una clave por etapa; el total del día es la suma de todas. */
function dailyTotals(series: DashboardDailyEntry[]) {
  return series.map((entry) => ({
    date: entry.date,
    total: Object.entries(entry).reduce((sum, [key, value]) => (key === "date" || typeof value !== "number" ? sum : sum + value), 0),
  }))
}

export function NewOpportunitiesTrend({ series, isLoading, className }: NewOpportunitiesTrendProps) {
  const { t } = useTranslation()
  const days = dailyTotals(series ?? [])
  const total = days.reduce((sum, day) => sum + day.total, 0)
  const peak = Math.max(0, ...days.map((day) => day.total))
  const label = (date: string) => dayFormat.format(new Date(`${date}T00:00:00Z`))

  const renderBody = () => {
    if (isLoading && !series) return <Skeleton className="h-28 w-full rounded-lg" />
    if (total === 0) return <p className="text-sm text-muted-foreground">{t("dashboard.trend.empty")}</p>

    return (
      <figure>
        <div
          role="img"
          aria-label={t("dashboard.trend.chartLabel", { total, peak })}
          className="flex h-28 items-end gap-[3px] border-b border-border"
        >
          {days.map((day) => (
            <div
              key={day.date}
              title={`${label(day.date)}: ${day.total}`}
              // Los días en cero muestran un trazo mínimo para que la serie no parezca cortada.
              className={day.total > 0 ? "min-h-1 flex-1 rounded-t-[3px] bg-chart-1" : "h-0.5 flex-1 rounded-t-[3px] bg-border"}
              style={day.total > 0 ? { height: `${(day.total / peak) * 100}%` } : undefined}
            />
          ))}
        </div>
        <figcaption className="mt-2 flex justify-between text-xs tabular-nums text-muted-foreground">
          <span>{days[0] && label(days[0].date)}</span>
          <span>{t("dashboard.trend.peak", { count: peak })}</span>
          <span>{t("dashboard.trend.today")}</span>
        </figcaption>
        <table className="sr-only">
          <thead>
            <tr>
              <th scope="col">{t("dashboard.trend.dayHeader")}</th>
              <th scope="col">{t("dashboard.trend.countHeader")}</th>
            </tr>
          </thead>
          <tbody>
            {days.map((day) => (
              <tr key={day.date}>
                <td>{label(day.date)}</td>
                <td>{day.total}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </figure>
    )
  }

  return (
    <PanelSection
      id="panel-trend"
      title={t("dashboard.trend.title")}
      description={t("dashboard.trend.description")}
      aside={total > 0 ? <span className="font-semibold tabular-nums">{t(`dashboard.trend.total_${total === 1 ? "one" : "other"}`, { count: total.toLocaleString("es-AR") })}</span> : undefined}
      busy={isLoading}
      className={className}
    >
      {renderBody()}
    </PanelSection>
  )
}
