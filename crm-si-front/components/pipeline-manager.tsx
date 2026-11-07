"use client"

import { useCallback, useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { createPipeline, deletePipeline, getPipelines, updatePipeline, type Pipeline } from "@/lib/api/pipeline"
import { PipelineStagesManager } from "@/components/pipeline-stages-manager"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
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
  const [loadError, setLoadError] = useState(false)
  const [saving, setSaving] = useState(false)
  // El embudo se conserva al cerrar para que el título no quede vacío durante la animación.
  const [pipelineToDelete, setPipelineToDelete] = useState<Pipeline | null>(null)
  const [deleteOpen, setDeleteOpen] = useState(false)

  const refresh = useCallback(async () => {
    const items = await getPipelines()
    setPipelines(items)
    setSelectedId((current) => items.some((item) => item.id === current) ? current : items.find((item) => item.is_default)?.id ?? items[0]?.id ?? 0)
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    setLoadError(false)
    refresh().catch(() => setLoadError(true)).finally(() => setLoading(false))
  }, [refresh])

  useEffect(() => {
    load()
  }, [load])

  const selected = pipelines.find((pipeline) => pipeline.id === selectedId)
  useEffect(() => setRename(selected?.name ?? ""), [selected?.id, selected?.name])

  if (!canView) return null
  if (loading) return <div className="flex justify-center py-8"><Loader2 className="size-5 animate-spin text-muted-foreground" aria-label="Cargando embudos" /></div>

  const mutate = async (action: () => Promise<unknown>) => {
    setSaving(true)
    try { await action(); await refresh() }
    catch (error) { addToast({ type: "error", title: "No se pudo guardar", description: error instanceof Error ? error.message : "Intentá de nuevo." }) }
    finally { setSaving(false) }
  }

  const confirmDelete = async () => {
    if (!pipelineToDelete) return
    setSaving(true)
    try {
      await deletePipeline(pipelineToDelete.id)
    } catch (error) {
      addToast({ type: "error", title: "No se pudo eliminar el embudo", description: error instanceof Error ? error.message : "Intentá de nuevo." })
      return
    } finally {
      setSaving(false)
      setDeleteOpen(false)
    }
    await refresh().catch(() => setLoadError(true))
  }

  return (
    <SettingsBlock title="Embudos" description="Organizá procesos comerciales y sus etapas." icon={GitBranch} measure="prose">
      {loadError ? (
        <div className="space-y-3 py-4 text-center">
          <p className="text-sm text-destructive">No se pudieron cargar los embudos.</p>
          <Button variant="outline" onClick={load}>Reintentar</Button>
        </div>
      ) : (
      <div className="space-y-5">
        {canManage && <div className="flex gap-2"><Input value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Nombre del embudo" aria-label="Nombre del nuevo embudo" /><Button disabled={saving || !newName.trim()} onClick={() => void mutate(async () => { await createPipeline(newName.trim()); setNewName("") })}>Crear</Button></div>}
        <div className="flex flex-col gap-2 sm:flex-row">
          <Select value={selectedId ? String(selectedId) : ""} onValueChange={(value) => setSelectedId(Number(value))}>
            <SelectTrigger aria-label="Embudo seleccionado" className="sm:flex-1">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {pipelines.map((pipeline) => <SelectItem key={pipeline.id} value={String(pipeline.id)}>{pipeline.name}{pipeline.is_default ? " · Predeterminado" : ""}</SelectItem>)}
            </SelectContent>
          </Select>
          {canManage && selected && <Button variant="outline" disabled={saving || selected.is_default} onClick={() => void mutate(() => updatePipeline(selected.id, { is_default: true }))}><Star className="mr-2 size-4" aria-hidden="true" />Predeterminar</Button>}
        </div>
        {canManage && selected && <div className="flex gap-2"><Input value={rename} onChange={(e) => setRename(e.target.value)} aria-label="Nombre del embudo" /><Button variant="outline" disabled={saving || !rename.trim() || rename.trim() === selected.name} onClick={() => void mutate(() => updatePipeline(selected.id, { name: rename.trim() }))}>Renombrar</Button><Button variant="outline" size="icon" className="text-muted-foreground hover:text-destructive" disabled={saving || selected.is_default || (selected.opportunities_count ?? 0) > 0} title={selected.is_default ? "Elegí otro predeterminado antes de eliminarlo" : (selected.opportunities_count ?? 0) > 0 ? "Mové las oportunidades primero" : "Eliminar embudo"} aria-label={`Eliminar embudo ${selected.name}`} onClick={() => { setPipelineToDelete(selected); setDeleteOpen(true) }}><Trash2 className="size-4" aria-hidden="true" /></Button></div>}
        {selected && <PipelineStagesManager pipelineId={selected.id} onStagesChanged={() => void refresh()} />}
      </div>
      )}
      <AlertDialog open={deleteOpen} onOpenChange={(open) => { if (!saving) setDeleteOpen(open) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Eliminar el embudo {pipelineToDelete?.name}?</AlertDialogTitle>
            <AlertDialogDescription>Se eliminan el embudo y sus etapas. No se puede deshacer.</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={saving}>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              // Queda abierto mientras borra: el cierre llega en el finally.
              onClick={(event) => {
                event.preventDefault()
                void confirmDelete()
              }}
              disabled={saving}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {saving && <Loader2 className="mr-2 size-4 animate-spin" aria-hidden="true" />}
              Eliminar embudo
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </SettingsBlock>
  )
}
