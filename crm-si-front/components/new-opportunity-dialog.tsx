"use client"

import { useEffect, useState } from "react"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { getContacts, type Contact } from "@/lib/api/contacts"
import { createOpportunity } from "@/lib/api/opportunities"
import { getPipelineStages, type Pipeline, type PipelineStage } from "@/lib/api/pipeline"

export function NewOpportunityDialog({
  open,
  onOpenChange,
  pipelines,
  initialPipelineId,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  pipelines: Pipeline[]
  initialPipelineId: number
  onCreated: () => void
}) {
  const [contacts, setContacts] = useState<Contact[]>([])
  const [stages, setStages] = useState<PipelineStage[]>([])
  const [pipelineId, setPipelineId] = useState(initialPipelineId)
  const [contactId, setContactId] = useState("")
  const [stageId, setStageId] = useState("")
  const [title, setTitle] = useState("")
  const [value, setValue] = useState("")
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState("")

  useEffect(() => {
    if (!open) return
    setPipelineId(initialPipelineId)
    getContacts({ per_page: 100 }).then(setContacts).catch(() => setContacts([]))
  }, [open, initialPipelineId])

  useEffect(() => {
    if (!open || !pipelineId) return
    getPipelineStages(pipelineId).then((items) => {
      const sorted = [...items].sort((a, b) => a.sort_order - b.sort_order)
      setStages(sorted)
      setStageId(String(sorted.find((stage) => stage.is_default)?.id ?? sorted[0]?.id ?? ""))
    }).catch(() => setStages([]))
  }, [open, pipelineId])

  const submit = async () => {
    if (!contactId || !pipelineId || !stageId) {
      setError("Elegí un contacto, un pipeline y una etapa.")
      return
    }
    setSaving(true)
    setError("")
    try {
      await createOpportunity({
        contact_id: Number(contactId),
        pipeline_id: pipelineId,
        pipeline_stage_id: Number(stageId),
        title: title.trim() || undefined,
        value: value ? Number(value) : null,
        source_type: "manual",
      })
      onOpenChange(false)
      onCreated()
      setContactId("")
      setTitle("")
      setValue("")
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "No se pudo crear la oportunidad.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Nueva oportunidad</DialogTitle>
          <DialogDescription>Elegí el contacto y el proceso comercial que vas a seguir.</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <label className="block space-y-1.5 text-sm">Contacto
            <Select value={contactId} onValueChange={setContactId}>
              <SelectTrigger><SelectValue placeholder="Seleccionar contacto" /></SelectTrigger>
              <SelectContent>{contacts.map((contact) => <SelectItem key={contact.id} value={String(contact.id)}>{contact.name}</SelectItem>)}</SelectContent>
            </Select>
          </label>
          <label className="block space-y-1.5 text-sm">Pipeline
            <Select value={String(pipelineId)} onValueChange={(next) => setPipelineId(Number(next))}>
              <SelectTrigger><SelectValue placeholder="Seleccionar pipeline" /></SelectTrigger>
              <SelectContent>{pipelines.map((pipeline) => <SelectItem key={pipeline.id} value={String(pipeline.id)}>{pipeline.name}</SelectItem>)}</SelectContent>
            </Select>
          </label>
          <label className="block space-y-1.5 text-sm">Etapa
            <Select value={stageId} onValueChange={setStageId}>
              <SelectTrigger><SelectValue placeholder="Seleccionar etapa" /></SelectTrigger>
              <SelectContent>{stages.map((stage) => <SelectItem key={stage.id} value={String(stage.id)}>{stage.name}</SelectItem>)}</SelectContent>
            </Select>
          </label>
          <label className="block space-y-1.5 text-sm">Nombre de la oportunidad
            <Input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Ej. Implementación inicial" />
          </label>
          <label className="block space-y-1.5 text-sm">Importe
            <Input type="number" min="0" step="0.01" value={value} onChange={(event) => setValue(event.target.value)} placeholder="0,00" />
          </label>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button>
            <Button onClick={submit} disabled={saving || !pipelines.length || !stages.length}>{saving ? "Guardando…" : "Guardar oportunidad"}</Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
