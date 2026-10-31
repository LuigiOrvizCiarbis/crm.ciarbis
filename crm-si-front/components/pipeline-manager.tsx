"use client"

import { useCallback, useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { createPipeline, deletePipeline, getPipelines, updatePipeline, type Pipeline } from "@/lib/api/pipeline"
import { PipelineStagesManager } from "@/components/pipeline-stages-manager"
import { GitBranch, Loader2, Star, Trash2 } from "lucide-react"

export function PipelineManager() {
  const canView = usePermission(["pipeline_stages.view", "pipeline_stages.manage"])
  const canManage = usePermission("pipeline_stages.manage")
  const { addToast } = useToast()
  const [pipelines, setPipelines] = useState<Pipeline[]>([])
  const [selectedId, setSelectedId] = useState(0)
  const [newName, setNewName] = useState("")
  const [rename, setRename] = useState("")
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const refresh = useCallback(async () => {
    const items = await getPipelines()
    setPipelines(items)
    setSelectedId((current) => items.some((item) => item.id === current) ? current : items.find((item) => item.is_default)?.id ?? items[0]?.id ?? 0)
  }, [])

  useEffect(() => {
    refresh().catch(() => addToast({ type: "error", title: "Error", description: "No se pudieron cargar los pipelines." })).finally(() => setLoading(false))
  }, [refresh, addToast])

  const selected = pipelines.find((pipeline) => pipeline.id === selectedId)
  useEffect(() => setRename(selected?.name ?? ""), [selected?.id, selected?.name])

  if (!canView) return null
  if (loading) return <div className="flex justify-center py-8"><Loader2 className="size-5 animate-spin text-muted-foreground" /></div>

  const mutate = async (action: () => Promise<unknown>) => {
    setSaving(true)
    try { await action(); await refresh() }
    catch (error) { addToast({ type: "error", title: "No se pudo guardar", description: error instanceof Error ? error.message : "Intentá de nuevo." }) }
    finally { setSaving(false) }
  }

  return (
    <SettingsBlock title="Pipelines" description="Organizá procesos comerciales y sus etapas." icon={GitBranch} measure="prose">
      <div className="space-y-5">
        {canManage && <div className="flex gap-2"><Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Nombre del pipeline" /><Button disabled={saving || !newName.trim()} onClick={() => void mutate(async () => { await createPipeline(newName.trim()); setNewName("") })}>Crear</Button></div>}
        <div className="flex flex-col gap-2 sm:flex-row">
          <select aria-label="Pipeline seleccionado" value={selectedId || ""} onChange={(e) => setSelectedId(Number(e.target.value))} className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm">
            {pipelines.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.name}{pipeline.is_default ? " · Predeterminado" : ""}</option>)}
          </select>
          {canManage && selected && <Button variant="outline" disabled={saving || selected.is_default} onClick={() => void mutate(() => updatePipeline(selected.id, { is_default: true }))}><Star className="mr-2 size-4" />Predeterminar</Button>}
        </div>
        {canManage && selected && <div className="flex gap-2"><Input value={rename} onChange={(e) => setRename(e.target.value)} aria-label="Nombre del pipeline" /><Button variant="outline" disabled={saving || !rename.trim() || rename.trim() === selected.name} onClick={() => void mutate(() => updatePipeline(selected.id, { name: rename.trim() }))}>Renombrar</Button><Button variant="outline" className="text-destructive" disabled={saving || selected.is_default || (selected.opportunities_count ?? 0) > 0} title={selected.is_default ? "Elegí otro predeterminado antes de eliminarlo" : (selected.opportunities_count ?? 0) > 0 ? "Mové las oportunidades primero" : "Eliminar pipeline"} onClick={() => void mutate(() => deletePipeline(selected.id))}><Trash2 className="size-4" /></Button></div>}
        {selected && <PipelineStagesManager pipelineId={selected.id} onStagesChanged={() => void refresh()} />}
      </div>
    </SettingsBlock>
  )
}
