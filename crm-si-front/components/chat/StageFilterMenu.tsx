"use client"

import { useEffect, useMemo, useState } from "react"
import { Loader2, Milestone, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { getPipelines, getPipelineStages, type Pipeline, type PipelineStage } from "@/lib/api/pipeline"
import { useTranslation } from "@/hooks/useTranslation"

/** Clave para los chats sin etapa asignada. */
export const NO_STAGE_FILTER = "none"

interface StageFilterMenuProps {
  /** Ids de etapa como string, más `NO_STAGE_FILTER`. */
  selected: string[]
  onChange: (selected: string[]) => void
  align?: "start" | "center" | "end"
}

type PipelineGroup = { pipeline: Pipeline; stages: PipelineStage[] }

/**
 * Filtro por etapa, agrupado por pipeline. Un chat entra si su etapa o la de
 * alguna de sus oportunidades está elegida.
 */
export function StageFilterMenu({ selected, onChange, align = "start" }: StageFilterMenuProps) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [groups, setGroups] = useState<PipelineGroup[] | null>(null)

  const selectedSet = useMemo(() => new Set(selected), [selected])

  useEffect(() => {
    if (!open || groups) return
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
  }, [open, groups])

  const toggle = (key: string) => {
    onChange(selectedSet.has(key) ? selected.filter((item) => item !== key) : [...selected, key])
  }

  return (
    <div className="flex items-center gap-1">
      <DropdownMenu open={open} onOpenChange={setOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm" className="gap-2 bg-transparent">
            <Milestone className="h-4 w-4" />
            {t("chats.stageFilter.label")}
            {selected.length > 0 && (
              <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground">
                {selected.length}
              </span>
            )}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align={align} className="max-h-[min(70vh,28rem)] w-64 overflow-y-auto">
          <DropdownMenuLabel>{t("chats.stageFilter.title")}</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {groups === null ? (
            <div className="flex items-center gap-2 px-2 py-6 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {t("chats.stageFilter.loading")}
            </div>
          ) : (
            <>
              {groups.map(({ pipeline, stages }) => (
                <div key={pipeline.id} role="group" aria-label={pipeline.name}>
                  <p className="px-2 pb-1 pt-2 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    {pipeline.name}
                  </p>
                  {stages.map((stage) => (
                    <DropdownMenuCheckboxItem
                      key={stage.id}
                      checked={selectedSet.has(String(stage.id))}
                      onCheckedChange={() => toggle(String(stage.id))}
                      onSelect={(event) => event.preventDefault()}
                    >
                      <span className="mr-2 h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: stage.color }} />
                      <span className="truncate">{stage.name}</span>
                    </DropdownMenuCheckboxItem>
                  ))}
                </div>
              ))}
              {groups.length > 0 && <DropdownMenuSeparator />}
              <DropdownMenuCheckboxItem
                checked={selectedSet.has(NO_STAGE_FILTER)}
                onCheckedChange={() => toggle(NO_STAGE_FILTER)}
                onSelect={(event) => event.preventDefault()}
              >
                <span className="mr-2 h-2.5 w-2.5 shrink-0 rounded-full border border-dashed border-muted-foreground" />
                <span className="truncate text-muted-foreground">{t("chats.stageFilter.none")}</span>
              </DropdownMenuCheckboxItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
      {selected.length > 0 && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 px-2 text-muted-foreground"
          aria-label={t("chats.stageFilter.clear")}
          onClick={() => onChange([])}
        >
          <X className="h-4 w-4" />
        </Button>
      )}
    </div>
  )
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
  const stageIds = [conversation.pipeline_stage_id, ...(conversation.opportunity_stage_ids ?? [])].filter(Boolean)
  if (stageIds.length === 0) return selected.has(NO_STAGE_FILTER)
  return stageIds.some((stageId) => selected.has(String(stageId)))
}
