"use client"

import { useEffect, useState } from "react"
import { KanbanBoard } from "@/components/kanban-board"
import { SidebarLayout } from "@/components/SidebarLayout"
import { PipelineCompactHeader } from "@/components/pipeline-compact-header"
import { useToast } from "@/components/Toast"
import { getPipelines, type Pipeline } from "@/lib/api/pipeline"
import { NewOpportunityDialog } from "@/components/new-opportunity-dialog"
import { NewPipelineDialog } from "@/components/new-pipeline-dialog"
import { useTranslation } from "@/hooks/useTranslation"

export default function OportunidadesPage() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const [refreshKey, setRefreshKey] = useState(0)
  const [pipelines, setPipelines] = useState<Pipeline[]>([])
  const [selectedPipelineId, setSelectedPipelineId] = useState(0)
  const [newOpportunityOpen, setNewOpportunityOpen] = useState(false)
  const [newPipelineOpen, setNewPipelineOpen] = useState(false)
  const [stagesDialogOpen, setStagesDialogOpen] = useState(false)

  useEffect(() => {
    getPipelines().then((items) => {
      setPipelines(items)
      setSelectedPipelineId((current) => current || items.find((item) => item.is_default)?.id || items[0]?.id || 0)
    }).catch(() => setPipelines([]))
  }, [])

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

  return (
    <SidebarLayout>
      <PipelineCompactHeader
        onNewOpportunity={handleNewOpportunity}
        onStagesChanged={handleStagesChanged}
        pipelines={pipelines}
        selectedPipelineId={selectedPipelineId}
        onPipelineChange={setSelectedPipelineId}
        onNewPipeline={() => setNewPipelineOpen(true)}
        stagesDialogOpen={stagesDialogOpen}
        onStagesDialogOpenChange={setStagesDialogOpen}
      />

      <div className="flex-1 overflow-y-auto">
        <div className="px-4 md:px-6 lg:px-8 py-6 space-y-6">
          <KanbanBoard refreshKey={refreshKey} pipelineId={selectedPipelineId} />
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
          addToast({ type: "success", title: "Oportunidad creada", description: "Se agregó al pipeline seleccionado." })
          setRefreshKey((prev) => prev + 1)
        }}
      />
    </SidebarLayout>
  )
}
