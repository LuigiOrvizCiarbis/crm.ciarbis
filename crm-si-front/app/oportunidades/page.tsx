"use client"

import { useCallback, useEffect, useState } from "react"
import { usePathname, useRouter, useSearchParams } from "next/navigation"
import { GitBranch, Loader2 } from "lucide-react"
import { KanbanBoard } from "@/components/kanban-board"
import { SidebarLayout } from "@/components/SidebarLayout"
import { EMPTY_PIPELINE_FILTERS, PipelineCompactHeader, type PipelineFilters } from "@/components/pipeline-compact-header"
import { useToast } from "@/components/Toast"
import { EmptyState } from "@/components/EmptyState"
import { Button } from "@/components/ui/button"
import { getPipelines, type Pipeline } from "@/lib/api/pipeline"
import { NewOpportunityDialog } from "@/components/new-opportunity-dialog"
import { NewPipelineDialog } from "@/components/new-pipeline-dialog"
import { useTranslation } from "@/hooks/useTranslation"
import { usePermission } from "@/hooks/usePermission"

export default function OportunidadesPage() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const canManageStages = usePermission("pipeline_stages.manage")
  const [refreshKey, setRefreshKey] = useState(0)
  const [pipelines, setPipelines] = useState<Pipeline[]>([])
  const [pipelinesStatus, setPipelinesStatus] = useState<"loading" | "error" | "ready">("loading")
  const [selectedPipelineId, setSelectedPipelineId] = useState(0)
  const [newOpportunityOpen, setNewOpportunityOpen] = useState(false)
  const [newPipelineOpen, setNewPipelineOpen] = useState(false)
  const [stagesDialogOpen, setStagesDialogOpen] = useState(false)
  const [filters, setFilters] = useState<PipelineFilters>(EMPTY_PIPELINE_FILTERS)
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  // Deep link desde el Panel ("Ver en el embudo"): ?etapa={id}&embudo={id}. Sin `embudo` (enlaces
  // viejos) la etapa se busca en el embudo predeterminado.
  const etapaParam = searchParams.get("etapa")
  const embudoParam = searchParams.get("embudo")
  const focusStageId = etapaParam && /^\d+$/.test(etapaParam) ? Number(etapaParam) : null
  const defaultPipelineId = pipelines.find((item) => item.is_default)?.id ?? 0
  const linkedPipelineId = embudoParam && /^\d+$/.test(embudoParam) && pipelines.some((item) => item.id === Number(embudoParam))
    ? Number(embudoParam)
    : defaultPipelineId
  // Ajuste durante el render (no en un efecto) para que el tablero arranque ya en el embudo correcto.
  const [syncedFocusStageId, setSyncedFocusStageId] = useState<number | null>(null)
  if (linkedPipelineId && focusStageId !== syncedFocusStageId) {
    setSyncedFocusStageId(focusStageId)
    if (focusStageId && selectedPipelineId !== linkedPipelineId) setSelectedPipelineId(linkedPipelineId)
  }

  const loadPipelines = useCallback(() => {
    setPipelinesStatus("loading")
    getPipelines().then((items) => {
      setPipelines(items)
      setSelectedPipelineId((current) => current || items.find((item) => item.is_default)?.id || items[0]?.id || 0)
      setPipelinesStatus("ready")
    }).catch(() => setPipelinesStatus("error"))
  }, [])

  useEffect(() => {
    loadPipelines()
  }, [loadPipelines])

  const handleNewOpportunity = (): void => {
    setNewOpportunityOpen(true)
  }

  const handlePipelineCreated = (pipeline: Pipeline, { empty }: { empty: boolean }): void => {
    setPipelines((current) => [...current, pipeline])
    setSelectedPipelineId(pipeline.id)
    setRefreshKey((prev) => prev + 1)
    addToast({ type: "success", title: t("pipeline.create.created"), description: t("pipeline.create.createdDesc", { name: pipeline.name }) })
    // Sin etapas el tablero queda vacío: llevar directo a armarlas.
    if (empty) setStagesDialogOpen(true)
  }

  const handleStagesChanged = (): void => {
    setRefreshKey((prev) => prev + 1)
  }

  const handleStageFocusHandled = useCallback((): void => {
    const params = new URLSearchParams(searchParams.toString())
    params.delete("etapa")
    params.delete("embudo")
    const query = params.toString()
    router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false })
  }, [pathname, router, searchParams])

  return (
    <SidebarLayout>
      <PipelineCompactHeader
        onNewOpportunity={handleNewOpportunity}
        filters={filters}
        onFiltersChange={setFilters}
        onStagesChanged={handleStagesChanged}
        pipelines={pipelines}
        selectedPipelineId={selectedPipelineId}
        onPipelineChange={setSelectedPipelineId}
        onNewPipeline={() => setNewPipelineOpen(true)}
        stagesDialogOpen={stagesDialogOpen}
        onStagesDialogOpenChange={setStagesDialogOpen}
      />

      {/* En teléfono el tablero ocupa el alto disponible y cada columna scrollea por dentro. */}
      <div className="flex-1 overflow-y-auto max-md:flex max-md:min-h-0 max-md:flex-col">
        <div className="px-4 md:px-6 lg:px-8 py-6 space-y-6 max-md:flex max-md:min-h-0 max-md:flex-1 max-md:flex-col max-md:py-4">
          {pipelinesStatus === "loading" ? (
            <div role="status" className="flex items-center justify-center py-12">
              <Loader2 className="w-8 h-8 animate-spin text-muted-foreground" aria-hidden="true" />
              <span className="sr-only">Cargando embudos</span>
            </div>
          ) : pipelinesStatus === "error" ? (
            <div role="alert" className="text-center py-12">
              <p className="mb-4 font-medium text-destructive">No se pudieron cargar los embudos.</p>
              <Button onClick={loadPipelines} variant="outline">Reintentar</Button>
            </div>
          ) : selectedPipelineId ? (
            <KanbanBoard
              refreshKey={refreshKey}
              pipelineId={selectedPipelineId}
              filters={filters}
              onClearFilters={() => setFilters(EMPTY_PIPELINE_FILTERS)}
              onManageStages={canManageStages ? () => setStagesDialogOpen(true) : undefined}
              // Con otro embudo elegido la etapa no está en el tablero: no se intenta enfocar.
              focusStageId={selectedPipelineId === linkedPipelineId ? focusStageId : null}
              onStageFocusHandled={handleStageFocusHandled}
            />
          ) : (
            <EmptyState
              icon={GitBranch}
              title="Todavía no hay embudos"
              description={canManageStages ? "Creá un embudo para empezar a seguir tus oportunidades." : "Pedile a un administrador que cree el primer embudo."}
              action={canManageStages ? { label: "Crear embudo", onClick: () => setNewPipelineOpen(true) } : undefined}
            />
          )}
        </div>
      </div>
      <NewPipelineDialog
        open={newPipelineOpen}
        onOpenChange={setNewPipelineOpen}
        pipelines={pipelines}
        onCreated={handlePipelineCreated}
      />
      <NewOpportunityDialog
        open={newOpportunityOpen}
        onOpenChange={setNewOpportunityOpen}
        pipelines={pipelines}
        initialPipelineId={selectedPipelineId}
        onCreated={() => {
          addToast({ type: "success", title: "Oportunidad creada", description: "Se agregó al embudo seleccionado." })
          setRefreshKey((prev) => prev + 1)
        }}
      />
    </SidebarLayout>
  )
}
