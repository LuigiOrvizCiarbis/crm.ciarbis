"use client"

import Link from "next/link"
import type { ReactNode } from "react"
import { ArrowUpRight } from "lucide-react"
import { PlatformIcon } from "@/components/chat/PlatformIcon"
import { PanelSection } from "@/components/dashboard/PanelSection"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardCollections, DashboardRevenue, DashboardRevenueChannel } from "@/lib/api/dashboard"
import { formatCompactCurrency } from "@/lib/currency"
import { channelKey } from "@/lib/utils/channelHelpers"
import { cn } from "@/lib/utils"

interface RevenueSectionProps {
  revenue?: DashboardRevenue
  isLoading: boolean
  /** El período admite meta (mes, trimestre, año). */
  goalApplies: boolean
  /**
   * Hay canal, vendedor o sucursal elegidos: la meta es de todo el equipo y compararla
   * contra una parte confunde, así que se oculta.
   */
  narrowed: boolean
  /** Puede definir la meta (dueño o quien ve todo el equipo). */
  canSetGoal: boolean
  /** Puede editar la probabilidad de las etapas. */
  canSetProbabilities: boolean
  /** Hay canal, vendedor o embudo elegidos, que la cobranza no usa. */
  collectionsIgnoreFilters: boolean
}

type GoalProps = Pick<RevenueSectionProps, "goalApplies" | "narrowed" | "canSetGoal" | "canSetProbabilities"> & { revenue: DashboardRevenue }

type Translate = ReturnType<typeof useTranslation>["t"]

const money = (value: number, currency?: string) => formatCompactCurrency(value, currency)
const pct = (part: number, total: number) => (total > 0 ? Math.round((part / total) * 100) : 0)

const inlineLink =
  "inline-flex items-center gap-0.5 rounded-sm font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"

/** Puede ocupar dos líneas en el teléfono: la flecha va pegada a la última palabra. */
function ConfigLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="rounded-sm font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50">
      {children}
      <ArrowUpRight className="ml-0.5 inline size-3.5 align-[-0.125em]" aria-hidden="true" />
    </Link>
  )
}

/** ¿Llego a la meta?, ¿cuánto entró a la caja? y ¿qué canales venden? */
export function RevenueSection({ revenue, isLoading, collectionsIgnoreFilters, ...goalProps }: RevenueSectionProps) {
  const { t } = useTranslation()
  const loading = isLoading && !revenue

  return (
    <PanelSection id="panel-revenue" title={t("dashboard.revenue.title")} busy={isLoading}>
      {loading || !revenue ? (
        <RevenueSkeleton />
      ) : (
        <div className="space-y-6">
          <GoalProgress revenue={revenue} {...goalProps} />
          <div className={cn("grid gap-x-10 gap-y-6 border-t border-border pt-5", revenue.collections && "md:grid-cols-2")}>
            {revenue.collections && <Collections collections={revenue.collections} ignoresFilters={collectionsIgnoreFilters} />}
            <ChannelRevenue channels={revenue.by_channel} />
          </div>
        </div>
      )}
    </PanelSection>
  )
}

function GoalProgress(props: GoalProps) {
  const { t } = useTranslation()
  const { revenue, narrowed } = props
  const won = revenue.won_value
  const projected = revenue.projected_value
  const goal = narrowed ? null : revenue.goal

  const headline = goal != null
    ? won >= goal
      ? t("dashboard.revenue.goalReached", { won: money(won), goal: money(goal), over: money(won - goal) })
      : t("dashboard.revenue.towardGoal", { won: money(won), goal: money(goal), pct: pct(won, goal) })
    : won > 0
      ? t("dashboard.revenue.won", { won: money(won) })
      : t("dashboard.revenue.noWins")

  // La barra necesita algo contra qué medirse: la meta o el proyectado.
  const scale = Math.max(goal ?? 0, projected ?? 0, won)
  const showBar = scale > 0 && (goal != null || projected != null)

  return (
    <div>
      <p className="text-lg font-semibold tracking-tight text-balance tabular-nums">{headline}</p>
      {showBar && <ProgressBar won={won} projected={projected} goal={goal} scale={scale} />}
      <ForecastLine revenue={revenue} goal={goal} />
      <SetupHints {...props} />
    </div>
  )
}

function ProgressBar({ won, projected, goal, scale }: { won: number; projected: number | null; goal: number | null; scale: number }) {
  const { t } = useTranslation()
  const width = (value: number) => `${Math.min(100, (value / scale) * 100)}%`
  const extra = projected != null ? Math.max(0, projected - won) : 0

  const label = [
    t("dashboard.revenue.legend.won") + " " + money(won),
    projected != null ? t("dashboard.revenue.legend.projected") + " " + money(projected) : null,
    goal != null ? t("dashboard.revenue.legend.goal") + " " + money(goal) : null,
  ].filter(Boolean).join(", ")

  return (
    <>
      {/* La marca de la meta sobresale de la barra: se lee aunque lo proyectado la tape. */}
      <div role="img" aria-label={label} className="relative mt-5 py-1">
        <div className="flex h-2.5 overflow-hidden rounded-full bg-muted">
          <span className="h-full bg-primary" style={{ width: width(won) }} />
          {extra > 0 && <span className="h-full bg-primary/35" style={{ width: width(extra) }} />}
        </div>
        {goal != null && (
          <span
            aria-hidden="true"
            className="absolute inset-y-0 w-0.5 -translate-x-1/2 rounded-full bg-foreground"
            style={{ left: width(goal) }}
          />
        )}
      </div>
      <ul className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm" aria-hidden="true">
        <LegendItem marker={<span className="size-2.5 rounded-full bg-primary" />} label={t("dashboard.revenue.legend.won")} />
        {extra > 0 && <LegendItem marker={<span className="size-2.5 rounded-full bg-primary/35" />} label={t("dashboard.revenue.legend.projected")} />}
        {goal != null && <LegendItem marker={<span className="h-3 w-0.5 rounded-full bg-foreground" />} label={t("dashboard.revenue.legend.goal")} />}
      </ul>
    </>
  )
}

function LegendItem({ marker, label }: { marker: ReactNode; label: string }) {
  return (
    <li className="flex items-center gap-2 text-muted-foreground">
      <span className="flex shrink-0 items-center">{marker}</span>
      {label}
    </li>
  )
}

function ForecastLine({ revenue, goal }: { revenue: DashboardRevenue; goal: number | null }) {
  const { t } = useTranslation()
  const projected = revenue.projected_value
  if (projected == null || projected <= revenue.won_value) return null

  return (
    <p className="mt-4 text-sm text-muted-foreground">
      <span className="font-medium text-foreground tabular-nums">{t("dashboard.revenue.projected", { value: money(projected) })}</span>{" "}
      {goal != null ? t("dashboard.revenue.projectedOfGoal", { pct: pct(projected, goal) }) : t("dashboard.revenue.projectedHow")}
    </p>
  )
}

/** Lo que falta configurar para que el bloque diga más: meta y probabilidades. */
function SetupHints({ revenue, goalApplies, narrowed, canSetGoal, canSetProbabilities }: GoalProps) {
  const { t } = useTranslation()
  const hints: ReactNode[] = []

  if (narrowed && revenue.goal != null) {
    hints.push(<span key="narrowed">{t("dashboard.revenue.goalHidden")}</span>)
  } else if (goalApplies && revenue.goal == null && canSetGoal) {
    hints.push(<ConfigLink key="goal" href="/configuracion/metas">{t("dashboard.revenue.setGoal")}</ConfigLink>)
  }

  // Sólo importa si hay oportunidades abiertas en etapas sin probabilidad.
  if (revenue.stages_without_probability > 0) {
    const text = revenue.projected_value == null
      ? t("dashboard.revenue.setProbabilities")
      : plural(t, "dashboard.revenue.stagesWithout", revenue.stages_without_probability)
    hints.push(canSetProbabilities
      ? <ConfigLink key="prob" href="/configuracion/embudos">{text}</ConfigLink>
      : <span key="prob">{text}</span>)
  }

  if (hints.length === 0) return null
  return (
    <p className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">{hints}</p>
  )
}

const plural = (t: Translate, key: string, count: number, params: Record<string, string | number> = {}) =>
  t(`${key}_${count === 1 ? "one" : "other"}`, { count: count.toLocaleString("es-AR"), ...params })

function ColumnHeading({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
      <h3 className="text-sm font-semibold">{title}</h3>
      {children}
    </div>
  )
}

function Collections({ collections, ignoresFilters }: { collections: DashboardCollections; ignoresFilters: boolean }) {
  const { t } = useTranslation()
  const { currency } = collections
  const toUnits = (cents: number) => cents / 100

  return (
    <div className="min-w-0">
      <ColumnHeading title={t("dashboard.revenue.collections.title")}>
        <Link href="/invoices" className={cn(inlineLink, "text-sm")}>
          {t("dashboard.revenue.collections.open")}
          <ArrowUpRight className="size-3.5" aria-hidden="true" />
        </Link>
      </ColumnHeading>
      <dl className="mt-4 grid grid-cols-2 gap-x-6">
        <div>
          <dt className="text-sm text-muted-foreground">{t("dashboard.revenue.collections.collected")}</dt>
          <dd className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{money(toUnits(collections.collected_cents), currency)}</dd>
        </div>
        <div>
          <dt className="text-sm text-muted-foreground">{t("dashboard.revenue.collections.outstanding")}</dt>
          <dd className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{money(toUnits(collections.outstanding_cents), currency)}</dd>
        </div>
      </dl>
      {collections.overdue_cents > 0 ? (
        <p className="mt-2 text-sm font-medium text-warning tabular-nums">
          {plural(t, "dashboard.revenue.collections.overdue", collections.overdue_count, { value: money(toUnits(collections.overdue_cents), currency) })}
        </p>
      ) : collections.outstanding_cents > 0 ? (
        <p className="mt-2 text-sm text-muted-foreground">{t("dashboard.revenue.collections.noneOverdue")}</p>
      ) : null}
      {collections.other_currencies && (
        <p className="mt-2 text-xs text-muted-foreground">{t("dashboard.revenue.collections.otherCurrencies", { currency })}</p>
      )}
      {ignoresFilters && <p className="mt-2 text-xs text-muted-foreground">{t("dashboard.revenue.collections.scope")}</p>}
    </div>
  )
}

function ChannelRevenue({ channels }: { channels: DashboardRevenueChannel[] }) {
  const { t } = useTranslation()
  const max = Math.max(1, ...channels.map((row) => row.won_value))

  return (
    <div className="min-w-0">
      <ColumnHeading title={t("dashboard.revenue.channels.title")} />
      {channels.length === 0 ? (
        <p className="mt-4 text-sm text-muted-foreground">{t("dashboard.revenue.channels.empty")}</p>
      ) : (
        <ul className="mt-4 space-y-4">
          {channels.map((row) => {
            const closed = row.won_count + row.lost_count
            return (
              <li key={row.channel_id ?? "none"}>
                <div className="flex items-center gap-2 text-sm">
                  {row.channel_id != null && (
                    <PlatformIcon type={channelKey(row.channel_type)} className="size-4 shrink-0" aria-hidden="true" />
                  )}
                  <span className="min-w-0 flex-1 truncate">{row.channel_name ?? t("dashboard.revenue.channels.none")}</span>
                  <span className="font-medium tabular-nums">{money(row.won_value)}</span>
                </div>
                <div className="mt-1.5 h-1.5 rounded-full bg-muted" aria-hidden="true">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${(row.won_value / max) * 100}%` }} />
                </div>
                <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                  {plural(t, "dashboard.revenue.channels.won", row.won_count)}
                  <span aria-hidden="true"> · </span>
                  {t("dashboard.revenue.channels.closeRate", { pct: pct(row.won_count, closed) })}
                </p>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

function RevenueSkeleton() {
  return (
    <div className="space-y-6">
      <div>
        <Skeleton className="h-6 w-full max-w-sm" />
        <Skeleton className="mt-5 h-2.5 w-full rounded-full" />
        <Skeleton className="mt-4 h-4 w-64" />
      </div>
      <div className="grid gap-x-10 gap-y-6 border-t border-border pt-5 md:grid-cols-2">
        {[0, 1].map((column) => (
          <div key={column} className="space-y-3">
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-8 w-40" />
            <Skeleton className="h-4 w-full" />
          </div>
        ))}
      </div>
    </div>
  )
}
