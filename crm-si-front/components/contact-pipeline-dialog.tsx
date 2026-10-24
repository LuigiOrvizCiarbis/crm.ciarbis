"use client"

import { useEffect, useState } from "react"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { createOpportunity, getOpportunities, updateOpportunityStage } from "@/lib/api/opportunities"
import { getPipelineStages, getPipelines, type Pipeline, type PipelineStage } from "@/lib/api/pipeline"

type ExistingOpportunity = { id: number; title: string; pipeline_id: number; pipeline_stage_id: number | null; pipeline?: { name: string } }

export function ContactPipelineDialog({
  contact,
  conversationId,
  open,
  onOpenChange,
  onSaved,
}: {
  contact: { id: number; name: string; conversations?: { id: number }[] } | null
  /** Desde un chat: la oportunidad se vincula a esa conversación y sólo se puede crear. */
  conversationId?: number
  open: boolean
  onOpenChange: (open: boolean) => void
  onSaved: () => void
}) {
  const [pipelines, setPipelines] = useState<Pipeline[]>([])
  const [stages, setStages] = useState<PipelineStage[]>([])
  const [opportunities, setOpportunities] = useState<ExistingOpportunity[]>([])
  const [pipelineId, setPipelineId] = useState(0)
  const [stageId, setStageId] = useState(0)
  const [opportunityId, setOpportunityId] = useState(0)
  const [mode, setMode] = useState<"create" | "move">("create")
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState("")

  useEffect(() => {
    if (!open || !contact) return
    Promise.all([getPipelines(), getOpportunities({ contactId: contact.id })]).then(([pipelineItems, opportunityItems]) => {
      setPipelines(pipelineItems)
      setMode("create")
      setOpportunityId(0)
      setError("")
      setOpportunities(opportunityItems as ExistingOpportunity[])
      setPipelineId(pipelineItems.find((item) => item.is_default)?.id ?? pipelineItems[0]?.id ?? 0)
    }).catch(() => setError("No se pudieron cargar los datos."))
  }, [open, contact])

  useEffect(() => {
    if (!open || !pipelineId) return
    getPipelineStages(pipelineId).then((items) => {
      setStages(items.sort((a, b) => a.sort_order - b.sort_order))
      setStageId(items.find((item) => item.is_default)?.id ?? items[0]?.id ?? 0)
    }).catch(() => setStages([]))
  }, [open, pipelineId])

  const linkedConversationId = conversationId ?? contact?.conversations?.[0]?.id ?? null

  const save = async () => {
    if (!contact || !pipelineId || !stageId) return setError("Elegí un pipeline y una etapa.")
    setSaving(true)
    setError("")
    try {
      if (mode === "move") {
        if (!opportunityId) throw new Error("Elegí una oportunidad para mover.")
        await updateOpportunityStage(opportunityId, stageId, pipelineId)
      } else {
        await createOpportunity({
          contact_id: contact.id,
          conversation_id: linkedConversationId,
          pipeline_id: pipelineId,
          pipeline_stage_id: stageId,
          title: `Oportunidad - ${contact.name}`,
          source_type: linkedConversationId ? "conversation" : "manual",
        })
      }
      onSaved()
      onOpenChange(false)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "No se pudo guardar.")
    } finally {
      setSaving(false)
    }
  }

  return <Dialog open={open} onOpenChange={onOpenChange}><DialogContent>
    <DialogHeader>
      <DialogTitle>{conversationId ? `Nueva oportunidad para ${contact?.name}` : `Agregar ${contact?.name} al pipeline`}</DialogTitle>
      <DialogDescription>{conversationId ? "Queda vinculada a este chat." : "Creá una oportunidad o mové una existente."}</DialogDescription>
    </DialogHeader>
    <div className="space-y-4">
      {!conversationId && <Select value={mode} onValueChange={(value) => setMode(value as "create" | "move")}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="create">Crear oportunidad</SelectItem><SelectItem value="move" disabled={!opportunities.length}>Mover oportunidad existente</SelectItem></SelectContent></Select>}
      {mode === "move" && <Select value={String(opportunityId || "")} onValueChange={(value) => setOpportunityId(Number(value))}><SelectTrigger><SelectValue placeholder="Elegir oportunidad" /></SelectTrigger><SelectContent>{opportunities.map((opportunity) => <SelectItem key={opportunity.id} value={String(opportunity.id)}>{opportunity.title}</SelectItem>)}</SelectContent></Select>}
      <Select value={String(pipelineId || "")} onValueChange={(value) => setPipelineId(Number(value))}><SelectTrigger><SelectValue placeholder="Elegir pipeline" /></SelectTrigger><SelectContent>{pipelines.map((pipeline) => <SelectItem key={pipeline.id} value={String(pipeline.id)}>{pipeline.name}</SelectItem>)}</SelectContent></Select>
      <Select value={String(stageId || "")} onValueChange={(value) => setStageId(Number(value))}><SelectTrigger><SelectValue placeholder="Elegir etapa" /></SelectTrigger><SelectContent>{stages.map((stage) => <SelectItem key={stage.id} value={String(stage.id)}>{stage.name}</SelectItem>)}</SelectContent></Select>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    </div>
    <DialogFooter><Button variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button><Button disabled={saving || !pipelines.length || !stages.length} onClick={() => void save()}>{saving ? "Guardando…" : mode === "move" ? "Mover oportunidad" : "Crear oportunidad"}</Button></DialogFooter>
  </DialogContent></Dialog>
}
