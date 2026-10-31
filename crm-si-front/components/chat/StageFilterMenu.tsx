"use client"

import { useEffect, useMemo, useState, type CSSProperties } from "react"
import { Check, Loader2, Milestone, Minus, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Drawer, DrawerContent, DrawerFooter, DrawerTitle } from "@/components/ui/drawer"
import { useIsMobile } from "@/hooks/use-mobile"
import { getPipelines, getPipelineStages, type Pipeline, type PipelineStage } from "@/lib/api/pipeline"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"

/** Clave para los chats sin etapa asignada. */
export const NO_STAGE_FILTER = "none"

type PipelineGroup = { pipeline: Pipeline; stages: PipelineStage[] }

interface StageFilterMenuProps {
  /** Ids de etapa como string, más `NO_STAGE_FILTER`. */
  selected: string[]
  onChange: (selected: string[]) => void
  /** Chats por clave de etapa, sin contar el propio filtro de etapas. */
  counts?: Map<string, number>
  /** Chats que quedan con el filtro aplicado (botón "Ver N chats" en mobile). */
  resultCount?: number
  /** Mobile: además de cerrar el drawer, llevar a la lista (p. ej. cerrar el panel de canales). */
  onShowResults?: () => void
}

/**
 * Filtro por etapa, una pestaña por pipeline. Un chat entra si su etapa o la
 * de alguna de sus oportunidades está elegida. En desktop es un popover; en
 * mobile, un drawer inferior para tocar con el pulgar.
 */
export function StageFilterMenu({ selected, onChange, counts, resultCount, onShowResults }: StageFilterMenuProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [open, setOpen] = useState(false)
  const [groups, setGroups] = useState<PipelineGroup[] | null>(null)

  // Se cargan al abrir por primera vez; los chips necesitan nombres y colores.
  useEffect(() => {
    if ((!open && selected.length === 0) || groups) return
    let cancelled = false
    getPipelines()
      .then((pipelines) => Promise.all(pipelines.map(async (pipeline) => ({
        pipeline,
        stages: [...await getPipelineStages(pipeline.id)].sort((a, b) => a.sort_order - b.sort_order),
      }))))
      .catch(() => [])
      .then((loaded) => {
        if (!cancelled) setGroups(loaded.filter((group) => group.stages.length > 0))
      })
    return () => {
      cancelled = true
    }
  }, [open, selected.length, groups])

  const selectedSet = useMemo(() => new Set(selected), [selected])
  const stageById = useMemo(() => {
    const map = new Map<string, { stage: PipelineStage; pipeline: Pipeline }>()
    groups?.forEach(({ pipeline, stages }) => stages.forEach((stage) => map.set(String(stage.id), { stage, pipeline })))
    return map
  }, [groups])

  const trigger = (
    <Button
      variant="outline"
      size="sm"
      className={cn("gap-2 bg-transparent", selected.length > 0 && "border-primary/40 text-foreground")}
      onClick={isMobile ? () => setOpen(true) : undefined}
    >
      <Milestone className="h-4 w-4" />
      {t("chats.stageFilter.label")}
      {selected.length > 0 && (
        <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground">
          {selected.length}
        </span>
      )}
    </Button>
  )

  const panel = (
    <StagePanel
      groups={groups}
      selectedSet={selectedSet}
      counts={counts}
      onChange={onChange}
      selected={selected}
      touch={isMobile}
    />
  )

  return (
    <>
      {isMobile ? (
        <>
          {trigger}
          <Drawer open={open} onOpenChange={setOpen}>
            <DrawerContent className="max-h-[85vh]">
              <DrawerTitle className="sr-only">{t("chats.stageFilter.title")}</DrawerTitle>
              <div className="min-h-0 flex-1 overflow-y-auto">{panel}</div>
              <DrawerFooter className="border-t border-border pt-3">
                <Button
                  onClick={() => {
                    setOpen(false)
                    onShowResults?.()
                  }}
                >
                  {resultCount === undefined
                    ? t("chats.stageFilter.done")
                    : t("chats.stageFilter.showResults", { count: resultCount })}
                </Button>
              </DrawerFooter>
            </DrawerContent>
          </Drawer>
        </>
      ) : (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>{trigger}</PopoverTrigger>
          <PopoverContent align="start" className="w-[320px] p-0">{panel}</PopoverContent>
        </Popover>
      )}

      {/* Lo elegido queda a la vista: se saca de a una sin abrir el panel. */}
      {selected.length > 0 && (
        <ul className="flex w-full flex-wrap gap-1.5" aria-label={t("chats.stageFilter.active")}>
          {selected.map((key) => {
            const entry = stageById.get(key)
            const label = key === NO_STAGE_FILTER ? t("chats.stageFilter.none") : entry?.stage.name
            if (!label) return null
            return (
              <li key={key}>
                <button
                  type="button"
                  onClick={() => onChange(selected.filter((item) => item !== key))}
                  aria-label={t("chats.stageFilter.remove", { stage: label })}
                  className="group flex max-w-[220px] items-center gap-1.5 rounded-full border border-border bg-background/60 py-0.5 pl-2 pr-1 text-xs transition-colors hover:border-foreground/30"
                >
                  <StageDot color={entry?.stage.color} />
                  <span className="truncate">
                    {entry && groups && groups.length > 1 && (
                      <span className="text-muted-foreground">{entry.pipeline.name} · </span>
                    )}
                    {label}
                  </span>
                  <X className="h-3 w-3 shrink-0 text-muted-foreground group-hover:text-foreground" />
                </button>
              </li>
            )
          })}
          {selected.length > 1 && (
            <li>
              <button
                type="button"
                onClick={() => onChange([])}
                className="rounded-full px-2 py-0.5 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
              >
                {t("chats.stageFilter.clear")}
              </button>
            </li>
          )}
        </ul>
      )}
    </>
  )
}

function StagePanel({
  groups,
  selected,
  selectedSet,
  counts,
  onChange,
  touch,
}: {
  groups: PipelineGroup[] | null
  selected: string[]
  selectedSet: Set<string>
  counts?: Map<string, number>
  onChange: (selected: string[]) => void
  touch: boolean
}) {
  const { t } = useTranslation()
  const [activeId, setActiveId] = useState<number | null>(null)

  if (groups === null) {
    return (
      <div className="flex items-center gap-2 px-4 py-8 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        {t("chats.stageFilter.loading")}
      </div>
    )
  }

  // Arranca en el primer pipeline con algo elegido; si no, el predeterminado.
  const fallback = groups.find((g) => g.stages.some((s) => selectedSet.has(String(s.id))))
    ?? groups.find((g) => g.pipeline.is_default)
    ?? groups[0]
  const active = groups.find((g) => g.pipeline.id === activeId) ?? fallback

  const toggle = (key: string) => {
    onChange(selectedSet.has(key) ? selected.filter((item) => item !== key) : [...selected, key])
  }

  const activeKeys = active ? active.stages.map((stage) => String(stage.id)) : []
  const activePicked = activeKeys.filter((key) => selectedSet.has(key)).length
  const allPicked = activeKeys.length > 0 && activePicked === activeKeys.length
  const toggleAll = () => {
    const rest = selected.filter((key) => !activeKeys.includes(key))
    onChange(allPicked ? rest : [...rest, ...activeKeys])
  }

  const rowClass = cn(
    "flex w-full items-center gap-2.5 rounded-md px-2 text-left text-sm transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none",
    touch ? "py-3" : "py-1.5",
  )

  return (
    <div className="flex flex-col">
      <div className="flex items-center justify-between px-3 pb-2 pt-3">
        <p className="text-sm font-semibold">{t("chats.stageFilter.title")}</p>
        {selected.length > 0 && (
          <button type="button" onClick={() => onChange([])} className="text-xs text-muted-foreground hover:text-foreground">
            {t("chats.stageFilter.clear")}
          </button>
        )}
      </div>

      {/* Una pestaña por pipeline; el número es cuántas etapas hay elegidas en él. */}
      {groups.length > 1 && (
        <div role="tablist" className="flex gap-1 overflow-x-auto px-3 pb-2 [scrollbar-width:none]">
          {groups.map(({ pipeline, stages }) => {
            const isActive = pipeline.id === active?.pipeline.id
            const picked = stages.filter((s) => selectedSet.has(String(s.id))).length
            return (
              <button
                key={pipeline.id}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => setActiveId(pipeline.id)}
                className={cn(
                  "flex shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors",
                  touch ? "py-1.5" : "py-1",
                  isActive
                    ? "border-primary bg-primary/10 text-foreground"
                    : "border-border text-muted-foreground hover:text-foreground",
                )}
              >
                {pipeline.name}
                {picked > 0 && (
                  <span className="rounded-full bg-primary px-1.5 text-[10px] font-semibold leading-4 text-primary-foreground">
                    {picked}
                  </span>
                )}
              </button>
            )
          })}
        </div>
      )}

      {active && (
        <div role="tabpanel" aria-label={active.pipeline.name} className="border-t border-border p-1.5">
          <button type="button" role="checkbox" aria-checked={allPicked ? true : activePicked > 0 ? "mixed" : false} onClick={toggleAll} className={rowClass}>
            <CheckBox state={allPicked ? "on" : activePicked > 0 ? "mixed" : "off"} />
            <span className="flex-1 font-medium">{t("chats.stageFilter.wholePipeline")}</span>
          </button>

          <StageTrack stages={active.stages} selectedSet={selectedSet} />

          {active.stages.map((stage) => {
            const key = String(stage.id)
            const checked = selectedSet.has(key)
            const count = counts?.get(key) ?? 0
            return (
              <button
                key={stage.id}
                type="button"
                role="checkbox"
                aria-checked={checked}
                onClick={() => toggle(key)}
                className={rowClass}
                style={checked ? ({ backgroundColor: `color-mix(in oklch, ${stage.color} 14%, transparent)` } as CSSProperties) : undefined}
              >
                <CheckBox state={checked ? "on" : "off"} color={stage.color} />
                <StageDot color={stage.color} />
                <span className="min-w-0 flex-1 truncate">{stage.name}</span>
                {counts && (
                  <span className={cn("text-xs tabular-nums", count ? "text-muted-foreground" : "text-muted-foreground/40")}>
                    {count}
                  </span>
                )}
              </button>
            )
          })}
        </div>
      )}

      <div className="border-t border-border p-1.5">
        <button
          type="button"
          role="checkbox"
          aria-checked={selectedSet.has(NO_STAGE_FILTER)}
          onClick={() => toggle(NO_STAGE_FILTER)}
          className={rowClass}
        >
          <CheckBox state={selectedSet.has(NO_STAGE_FILTER) ? "on" : "off"} />
          <span className="h-2.5 w-2.5 shrink-0 rounded-full border border-dashed border-muted-foreground" />
          <span className="flex-1 text-muted-foreground">{t("chats.stageFilter.none")}</span>
          {counts && (
            <span className="text-xs tabular-nums text-muted-foreground">{counts.get(NO_STAGE_FILTER) ?? 0}</span>
          )}
        </button>
      </div>
    </div>
  )
}

/** Pista del pipeline: muestra de un vistazo qué tramo del embudo está elegido. */
function StageTrack({ stages, selectedSet }: { stages: PipelineStage[]; selectedSet: Set<string> }) {
  return (
    <div aria-hidden="true" className="flex gap-[3px] px-2 pb-2 pt-1">
      {stages.map((stage) => (
        <span
          key={stage.id}
          className="h-1 flex-1 rounded-full bg-border transition-colors duration-200"
          style={selectedSet.has(String(stage.id)) ? { backgroundColor: stage.color } : undefined}
        />
      ))}
    </div>
  )
}

function CheckBox({ state, color }: { state: "on" | "off" | "mixed"; color?: string }) {
  return (
    <span
      className={cn(
        "flex h-4 w-4 shrink-0 items-center justify-center rounded-[4px] border transition-colors",
        state === "off" ? "border-muted-foreground/50" : "border-transparent bg-primary text-primary-foreground",
      )}
      style={state !== "off" && color ? { backgroundColor: color, color: "white" } : undefined}
    >
      {state === "on" && <Check className="h-3 w-3" strokeWidth={3} />}
      {state === "mixed" && <Minus className="h-3 w-3" strokeWidth={3} />}
    </span>
  )
}

function StageDot({ color }: { color?: string }) {
  return <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color ?? "var(--muted-foreground)" }} />
}

/** Claves de etapa de un chat: la suya y las de sus oportunidades, o «sin etapa». */
export function stageKeysOf(conversation: { pipeline_stage_id?: number | null; opportunity_stage_ids?: number[] }) {
  const ids = [conversation.pipeline_stage_id, ...(conversation.opportunity_stage_ids ?? [])].filter(Boolean)
  return ids.length ? [...new Set(ids.map(String))] : [NO_STAGE_FILTER]
}

/**
 * Si el chat entra en el filtro: por su etapa o por la de alguna de sus
 * oportunidades. «Sin etapa» es no tener ninguna de las dos. Sin selección, entra todo.
 */
export function matchesStageFilter(
  conversation: { pipeline_stage_id?: number | null; opportunity_stage_ids?: number[] },
  selected: Set<string>,
) {
  if (selected.size === 0) return true
  return stageKeysOf(conversation).some((key) => selected.has(key))
}
