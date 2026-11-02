"use client"

import { useEffect, useState } from "react"
import { Filter, MessageSquare, Plus, Search, Settings2, Users } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { PipelineStagesManager } from "@/components/pipeline-stages-manager"
import { usePermission } from "@/hooks/usePermission"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectSeparator,
  SelectValue,
} from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"
import { useIsMobile } from "@/hooks/use-mobile"
import type { Pipeline } from "@/lib/api/pipeline"
import { getUsers, type SystemUser } from "@/lib/api/users"

export interface PipelineFilters {
  search: string
  /** Plataforma de origen (whatsapp, instagram, facebook, manual) o "all". */
  channel: string
  /** Id del vendedor asignado, "none" (sin asignar) o "all". */
  assignee: string
  status: "all" | "open" | "won" | "lost" | "archived"
  value: "all" | "low" | "medium" | "high"
  /** Período de la última actividad. */
  period: "all" | "today" | "week" | "month"
}

export const EMPTY_PIPELINE_FILTERS: PipelineFilters = {
  search: "",
  channel: "all",
  assignee: "all",
  status: "all",
  value: "all",
  period: "all",
}

/** Filtros del panel activos (sin contar la búsqueda, que ya está a la vista). */
export function countActiveFilters(filters: PipelineFilters): number {
  return (["channel", "assignee", "status", "value", "period"] as const).filter((key) => filters[key] !== "all").length
}

export function hasActiveFilters(filters: PipelineFilters): boolean {
  return filters.search.trim() !== "" || countActiveFilters(filters) > 0
}

interface PipelineCompactHeaderProps {
  onNewOpportunity?: () => void
  filters: PipelineFilters
  onFiltersChange: (filters: PipelineFilters) => void
  onStagesChanged?: () => void
  pipelines: Pipeline[]
  selectedPipelineId: number
  onPipelineChange: (pipelineId: number) => void
  onNewPipeline?: () => void
  /** Gestor de etapas controlado desde afuera (p. ej. al crear un pipeline vacío). */
  stagesDialogOpen?: boolean
  onStagesDialogOpenChange?: (open: boolean) => void
}

const NEW_PIPELINE_VALUE = "__new__"

export function PipelineCompactHeader({
  onNewOpportunity,
  filters,
  onFiltersChange,
  onStagesChanged,
  pipelines,
  selectedPipelineId,
  onPipelineChange,
  onNewPipeline,
  stagesDialogOpen,
  onStagesDialogOpenChange,
}: PipelineCompactHeaderProps) {
  const { t } = useTranslation()
  const canManageStages = usePermission("pipeline_stages.manage")
  const isMobile = useIsMobile()
  // Sin lista de usuarios (p. ej. sin permiso) no se ofrece el filtro por vendedor.
  const [salespeople, setSalespeople] = useState<SystemUser[]>([])
  const activeCount = countActiveFilters(filters)

  useEffect(() => {
    let cancelled = false
    getUsers().then((users) => {
      if (!cancelled) setSalespeople([...users].sort((a, b) => a.name.localeCompare(b.name, "es")))
    })
    return () => {
      cancelled = true
    }
  }, [])

  const setFilter = <K extends keyof PipelineFilters>(key: K, value: PipelineFilters[K]): void => {
    onFiltersChange({ ...filters, [key]: value })
  }

  return (
    // Hasta xl: dos filas (título + acciones; pipeline, búsqueda y filtros).
    // Desde xl vuelve a ser una sola fila de 75px, con el orden del DOM.
    <header className="sticky top-0 z-40 flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 md:gap-x-4 md:px-6 lg:px-8 xl:h-[75px] xl:flex-nowrap xl:gap-4 xl:py-0 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 border-b border-border">
      <div className="contents xl:flex xl:items-center xl:gap-2 xl:min-w-fit">
        <h1 className="order-1 min-w-0 flex-1 truncate text-base font-semibold tracking-tight md:text-xl xl:order-none xl:flex-none xl:overflow-visible">
          {t("pipeline.title")}
        </h1>
        <Select
          value={String(selectedPipelineId)}
          onValueChange={(value) => {
            // "Nuevo pipeline" es una acción, no una opción: no cambia la selección.
            if (value === NEW_PIPELINE_VALUE) onNewPipeline?.()
            else onPipelineChange(Number(value))
          }}
        >
          <SelectTrigger className="order-3 h-10 min-w-0 flex-1 text-left text-base md:text-sm xl:order-none xl:h-9 xl:w-[170px] xl:flex-none [&>span]:min-w-0 [&>span]:whitespace-nowrap [&>span]:text-ellipsis" aria-label="Embudo">
            <SelectValue placeholder="Elegir embudo" />
          </SelectTrigger>
          <SelectContent>
            {pipelines.map((pipeline) => <SelectItem key={pipeline.id} value={String(pipeline.id)}>{pipeline.name}</SelectItem>)}
            {canManageStages && onNewPipeline && (
              <>
                <SelectSeparator />
                <SelectItem value={NEW_PIPELINE_VALUE} className="text-muted-foreground focus:text-foreground">
                  <span className="flex items-center gap-2">
                    <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                    {t("pipeline.create.newPipeline")}
                  </span>
                </SelectItem>
              </>
            )}
          </SelectContent>
        </Select>
      </div>

      <div className="order-3 min-w-0 flex-1 xl:order-none xl:max-w-md">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <Input
            placeholder={t("pipeline.searchPlaceholder")}
            aria-label={t("pipeline.searchPlaceholder")}
            value={filters.search}
            onChange={(e) => setFilter("search", e.target.value)}
            className="pl-9 h-10 xl:h-9"
          />
        </div>
      </div>

      <div className="order-3 flex items-center gap-2 xl:order-none">
        {/* Canal y vendedor sólo entran en línea con pantalla ancha; si no, viven en el panel de filtros. */}
        <Select value={filters.channel} onValueChange={(value) => setFilter("channel", value)}>
          <SelectTrigger className="hidden h-9 w-[140px] 2xl:flex" aria-label={t("pipeline.filters.channels")}>
            <MessageSquare className="h-4 w-4 mr-2" aria-hidden="true" />
            <SelectValue placeholder={t("pipeline.filters.channels")} />
          </SelectTrigger>
          <SelectContent>
            <ChannelOptions />
          </SelectContent>
        </Select>

        {salespeople.length > 0 && (
          <Select value={filters.assignee} onValueChange={(value) => setFilter("assignee", value)}>
            <SelectTrigger className="hidden h-9 w-[140px] 2xl:flex" aria-label={t("pipeline.filters.salesperson")}>
              <Users className="h-4 w-4 mr-2" aria-hidden="true" />
              <SelectValue placeholder={t("pipeline.filters.salesperson")} />
            </SelectTrigger>
            <SelectContent>
              <SalespersonOptions salespeople={salespeople} />
            </SelectContent>
          </Select>
        )}

        <Sheet>
          <SheetTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="relative size-10 bg-transparent md:w-auto xl:h-9"
              title={t("pipeline.moreFilters")}
            >
              <Filter className="h-4 w-4 md:mr-2" aria-hidden="true" />
              <span className="sr-only md:not-sr-only">{t("pipeline.moreFilters")}</span>
              {activeCount > 0 && (
                <span className="absolute -right-1.5 -top-1.5 flex min-w-5 items-center justify-center rounded-full bg-primary px-1 text-xs font-medium tabular-nums text-primary-foreground md:static md:ml-2">
                  <span aria-hidden="true">{activeCount}</span>
                  <span className="sr-only">{t("pipeline.filters.activeCount", { count: activeCount })}</span>
                </span>
              )}
            </Button>
          </SheetTrigger>
          <SheetContent
            side={isMobile ? "bottom" : "right"}
            className={isMobile ? "max-h-[85dvh] gap-0 overflow-y-auto rounded-t-2xl pb-[max(env(safe-area-inset-bottom),1.5rem)]" : undefined}
          >
            <SheetHeader>
              <SheetTitle>{t("pipeline.filters.advancedTitle")}</SheetTitle>
              <SheetDescription>{t("pipeline.filters.advancedDesc")}</SheetDescription>
            </SheetHeader>
            <div className="mt-6 space-y-4 px-4 max-md:mt-2">
              <div className="space-y-4 2xl:hidden">
                <div>
                  <label htmlFor="pipeline-filter-channel" className="text-sm font-medium mb-2 block">{t("pipeline.filters.channels")}</label>
                  <Select value={filters.channel} onValueChange={(value) => setFilter("channel", value)}>
                    <SelectTrigger id="pipeline-filter-channel" className="text-base md:text-sm">
                      <SelectValue placeholder={t("pipeline.filters.channels")} />
                    </SelectTrigger>
                    <SelectContent>
                      <ChannelOptions />
                    </SelectContent>
                  </Select>
                </div>
                {salespeople.length > 0 && (
                  <div>
                    <label htmlFor="pipeline-filter-salesperson" className="text-sm font-medium mb-2 block">{t("pipeline.filters.salesperson")}</label>
                    <Select value={filters.assignee} onValueChange={(value) => setFilter("assignee", value)}>
                      <SelectTrigger id="pipeline-filter-salesperson" className="text-base md:text-sm">
                        <SelectValue placeholder={t("pipeline.filters.salesperson")} />
                      </SelectTrigger>
                      <SelectContent>
                        <SalespersonOptions salespeople={salespeople} />
                      </SelectContent>
                    </Select>
                  </div>
                )}
              </div>
              <div>
                <label htmlFor="pipeline-filter-status" className="text-sm font-medium mb-2 block">{t("pipeline.filters.status")}</label>
                <Select value={filters.status} onValueChange={(value) => setFilter("status", value as PipelineFilters["status"])}>
                  <SelectTrigger id="pipeline-filter-status" className="text-base md:text-sm">
                    <SelectValue placeholder={t("pipeline.filters.selectStatus")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">{t("pipeline.filters.statusAll")}</SelectItem>
                    <SelectItem value="open">{t("pipeline.filters.statusOpen")}</SelectItem>
                    <SelectItem value="won">{t("pipeline.filters.statusWon")}</SelectItem>
                    <SelectItem value="lost">{t("pipeline.filters.statusLost")}</SelectItem>
                    <SelectItem value="archived">{t("pipeline.filters.statusArchived")}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label htmlFor="pipeline-filter-value" className="text-sm font-medium mb-2 block">{t("pipeline.filters.valueRange")}</label>
                <Select value={filters.value} onValueChange={(value) => setFilter("value", value as PipelineFilters["value"])}>
                  <SelectTrigger id="pipeline-filter-value" className="text-base md:text-sm">
                    <SelectValue placeholder={t("pipeline.filters.selectValue")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">{t("pipeline.filters.statusAll")}</SelectItem>
                    <SelectItem value="low">{t("pipeline.filters.valueLow")}</SelectItem>
                    <SelectItem value="medium">{t("pipeline.filters.valueMid")}</SelectItem>
                    <SelectItem value="high">{t("pipeline.filters.valueHigh")}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label htmlFor="pipeline-filter-date" className="text-sm font-medium mb-2 block">{t("pipeline.filters.date")}</label>
                <Select value={filters.period} onValueChange={(value) => setFilter("period", value as PipelineFilters["period"])}>
                  <SelectTrigger id="pipeline-filter-date" className="text-base md:text-sm">
                    <SelectValue placeholder={t("pipeline.filters.datePeriod")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="today">{t("pipeline.filters.dateToday")}</SelectItem>
                    <SelectItem value="week">{t("pipeline.filters.dateWeek")}</SelectItem>
                    <SelectItem value="month">{t("pipeline.filters.dateMonth")}</SelectItem>
                    <SelectItem value="all">{t("pipeline.filters.dateAll")}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Button
                variant="outline"
                className="w-full"
                disabled={!hasActiveFilters(filters)}
                onClick={() => onFiltersChange(EMPTY_PIPELINE_FILTERS)}
              >
                {t("pipeline.filters.clear")}
              </Button>
            </div>
          </SheetContent>
        </Sheet>
      </div>

      <div className="order-2 flex items-center gap-2 xl:order-none xl:ml-auto">
        {canManageStages && (
          <Dialog open={stagesDialogOpen} onOpenChange={onStagesDialogOpenChange}>
            <DialogTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className="size-10 bg-transparent xl:size-9 2xl:w-auto"
                title={t("pipeline.stages.manage")}
              >
                <Settings2 className="h-4 w-4 2xl:mr-2" aria-hidden="true" />
                <span className="sr-only 2xl:not-sr-only">{t("pipeline.stages.manage")}</span>
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
              <DialogHeader>
                <DialogTitle>{t("pipeline.stages.manage")}</DialogTitle>
                <DialogDescription>{t("pipeline.stages.manageDesc")}</DialogDescription>
              </DialogHeader>
              <PipelineStagesManager pipelineId={selectedPipelineId} onStagesChanged={onStagesChanged} />
            </DialogContent>
          </Dialog>
        )}
        <Button onClick={onNewOpportunity} size="sm" className="h-10 xl:h-9" aria-label={t("pipeline.newOpportunity")}>
          <Plus className="h-4 w-4 md:mr-2" aria-hidden="true" />
          <span className="md:hidden">{t("pipeline.newOpportunityShort")}</span>
          <span className="max-md:hidden">{t("pipeline.newOpportunity")}</span>
        </Button>
      </div>
      {/* Corte de línea: en pantallas angostas pasa pipeline, búsqueda y filtros a la segunda fila. */}
      <div aria-hidden="true" className="order-2 h-0 basis-full xl:hidden" />
    </header>
  )
}

function ChannelOptions() {
  const { t } = useTranslation()
  return (
    <>
      <SelectItem value="all">{t("pipeline.filters.allChannels")}</SelectItem>
      <SelectItem value="whatsapp">WhatsApp</SelectItem>
      <SelectItem value="instagram">Instagram</SelectItem>
      <SelectItem value="facebook">Facebook</SelectItem>
      <SelectItem value="manual">{t("pipeline.filters.channelManual")}</SelectItem>
    </>
  )
}

function SalespersonOptions({ salespeople }: { salespeople: SystemUser[] }) {
  const { t } = useTranslation()
  return (
    <>
      <SelectItem value="all">{t("pipeline.filters.allSalespeople")}</SelectItem>
      <SelectItem value="none">{t("pipeline.filters.unassigned")}</SelectItem>
      {salespeople.map((user) => (
        <SelectItem key={user.id} value={String(user.id)}>{user.name}</SelectItem>
      ))}
    </>
  )
}
