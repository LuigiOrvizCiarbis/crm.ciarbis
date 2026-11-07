"use client"

import Link from "next/link"
import { ArrowUpRight, CircleCheck } from "lucide-react"
import { PanelSection } from "@/components/dashboard/PanelSection"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardStage } from "@/lib/api/dashboard"
import { formatCompactCurrency } from "@/lib/currency"
import { cn } from "@/lib/utils"

interface StalledStagesProps {
  stages?: DashboardStage[]
  staleAfterDays?: number
  isLoading: boolean
  className?: string
}

type Translate = ReturnType<typeof useTranslation>["t"]

const plural = (t: Translate, key: string, count: number) =>
  t(`${key}_${count === 1 ? "one" : "other"}`, { count: count.toLocaleString("es-AR") })

/** La etapa que más pesa es la que más valor tiene parado; a igual valor, la de más oportunidades. */
function mostStalled(stages: DashboardStage[]): DashboardStage | null {
  return stages.reduce<DashboardStage | null>((best, stage) => {
    if (stage.stale_count === 0) return best
    if (!best) return stage
    if (stage.stale_value !== best.stale_value) return stage.stale_value > best.stale_value ? stage : best
    return stage.stale_count > best.stale_count ? stage : best
  }, null)
}

/** Agrupa respetando el orden del backend (embudo principal primero, después por etapa). */
function groupByPipeline(stages: DashboardStage[]): { id: number; name: string; stages: DashboardStage[] }[] {
  const groups = new Map<number, { id: number; name: string; stages: DashboardStage[] }>()
  for (const stage of stages) {
    const group = groups.get(stage.pipeline_id) ?? { id: stage.pipeline_id, name: stage.pipeline_name, stages: [] }
    group.stages.push(stage)
    groups.set(stage.pipeline_id, group)
  }
  return [...groups.values()]
}

const stageHref = (stage: DashboardStage) => `/oportunidades?etapa=${stage.id}&embudo=${stage.pipeline_id}`

export function StalledStages({ stages, staleAfterDays = 14, isLoading, className }: StalledStagesProps) {
  const { t } = useTranslation()
  // Sólo etapas con oportunidades abiertas: las de cierre (Ganado, Perdido) quedan en 0 y serían ruido.
  const ordered = (stages ?? []).filter((stage) => stage.open_count > 0)
  const hasStages = (stages ?? []).length > 0
  // Misma escala para todos los embudos: así las barras se comparan entre grupos.
  const maxOpen = Math.max(1, ...ordered.map((stage) => stage.open_count))
  const totalOpen = ordered.reduce((sum, stage) => sum + stage.open_count, 0)
  const top = mostStalled(ordered)
  const groups = groupByPipeline(ordered)
  // Hay más de un embudo en la respuesta sólo cuando se eligió "Todos los embudos".
  const grouped = new Set((stages ?? []).map((stage) => stage.pipeline_id)).size > 1

  const renderStage = (stage: DashboardStage) => {
    const active = stage.open_count - stage.stale_count
    const isTop = top?.id === stage.id
    return (
      // Columna derecha de ancho fijo: así todas las pistas miden lo mismo y se comparan.
      <li key={stage.id} className="grid gap-x-4 gap-y-2 py-3.5 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_13.5rem] sm:items-center">
        <div className="flex min-w-0 items-baseline justify-between gap-3 sm:block">
          <p className="flex min-w-0 items-center gap-2 text-sm">
            <span className={cn("truncate", isTop ? "font-semibold" : "font-medium")}>{stage.name}</span>
            {isTop && (
              <span className="shrink-0 rounded-md border border-warning/30 bg-warning/10 px-1.5 py-px text-[11px] font-medium text-warning">
                {t("dashboard.stalled.mostStalled")}
              </span>
            )}
          </p>
          <p className="shrink-0 text-xs tabular-nums text-muted-foreground">{plural(t, "dashboard.stalled.open", stage.open_count)}</p>
        </div>

        {/* Largo total proporcional a las abiertas de la etapa; adentro, la parte parada. */}
        <div className="h-2.5 rounded-full bg-muted" aria-hidden="true">
          <div className="flex h-full overflow-hidden rounded-full" style={{ width: `${(stage.open_count / maxOpen) * 100}%` }}>
            <div className="h-full bg-chart-2" style={{ flexGrow: active }} />
            <div className="h-full bg-warning" style={{ flexGrow: stage.stale_count }} />
          </div>
        </div>

        <div className="flex items-center justify-between gap-2 sm:justify-end">
          <p className={cn("whitespace-nowrap text-sm tabular-nums", stage.stale_count > 0 ? "font-medium text-warning" : "text-muted-foreground")}>
            {stage.stale_count > 0 ? plural(t, "dashboard.stalled.stale", stage.stale_count) : t("dashboard.stalled.upToDate")}
            {stage.stale_value > 0 && <span className="font-normal text-muted-foreground"> · {formatCompactCurrency(stage.stale_value)}</span>}
          </p>
          <Link
            href={stageHref(stage)}
            aria-label={t("dashboard.stalled.viewStageInPipeline", { stage: stage.name, pipeline: stage.pipeline_name })}
            title={t("dashboard.stalled.viewStageInPipeline", { stage: stage.name, pipeline: stage.pipeline_name })}
            className="grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 pointer-coarse:size-10"
          >
            <ArrowUpRight className="size-4" aria-hidden="true" />
          </Link>
        </div>
      </li>
    )
  }

  const renderBody = () => {
    if (isLoading && !stages) {
      return (
        <div className="space-y-5">
          {[0, 1, 2, 3].map((row) => (
            <div key={row} className="grid gap-2 sm:grid-cols-[10rem_1fr_9rem] sm:items-center sm:gap-4">
              <Skeleton className="h-4 w-28" />
              <Skeleton className="h-2.5 w-full rounded-full" />
              <Skeleton className="h-4 w-24 sm:justify-self-end" />
            </div>
          ))}
        </div>
      )
    }

    if (!hasStages) {
      return (
        <p className="text-sm text-muted-foreground">
          {t("dashboard.stalled.noStages")}{" "}
          <Link href="/configuracion/embudos" className="font-medium text-primary underline-offset-4 hover:underline">
            {t("dashboard.stalled.configureStages")}
          </Link>
        </p>
      )
    }

    if (totalOpen === 0) return <p className="text-sm text-muted-foreground">{t("dashboard.stalled.noOpen")}</p>

    return (
      <>
        {top ? (
          <p className="text-sm leading-6 text-foreground">
            {t(grouped ? "dashboard.stalled.summaryWithPipeline" : "dashboard.stalled.summary", {
              stage: top.name,
              pipeline: top.pipeline_name,
              count: top.stale_count.toLocaleString("es-AR"),
              value: formatCompactCurrency(top.stale_value),
            })}{" "}
            <Link
              href={stageHref(top)}
              className="inline-flex items-center gap-0.5 rounded-sm font-semibold text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
              {t("dashboard.stalled.viewInPipeline")}
              <ArrowUpRight className="size-3.5" aria-hidden="true" />
            </Link>
          </p>
        ) : (
          <p className="flex items-start gap-2 text-sm leading-6 text-foreground">
            <CircleCheck className="mt-1 size-4 shrink-0 text-success" aria-hidden="true" />
            {t("dashboard.stalled.noneStale", { days: staleAfterDays })}
          </p>
        )}

        <div className="mt-5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground" aria-hidden="true">
          <span className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-chart-2" />{t("dashboard.stalled.legendActive")}</span>
          <span className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-warning" />{t("dashboard.stalled.legendStale")}</span>
        </div>

        {grouped ? (
          <div className="mt-3 space-y-5">
            {groups.map((group) => (
              <section key={group.id} aria-labelledby={`panel-stalled-pipeline-${group.id}`}>
                <h3 id={`panel-stalled-pipeline-${group.id}`} className="border-b border-border pb-2 text-sm font-semibold">
                  {group.name}
                </h3>
                <ul className="divide-y divide-border">{group.stages.map(renderStage)}</ul>
              </section>
            ))}
          </div>
        ) : (
          <ul className="mt-3 divide-y divide-border">{ordered.map(renderStage)}</ul>
        )}
      </>
    )
  }

  return (
    <PanelSection
      id="panel-stalled"
      title={t("dashboard.stalled.title")}
      description={t("dashboard.stalled.description", { days: staleAfterDays })}
      prominent
      busy={isLoading}
      className={className}
    >
      {renderBody()}
    </PanelSection>
  )
}
