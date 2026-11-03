"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { useDndAccessibility } from "@/hooks/useDndAccessibility"
import {
  fetchPipelineStages,
  createPipelineStage,
  updatePipelineStage,
  deletePipelineStage,
  reorderPipelineStages,
  type PipelineStage,
} from "@/lib/api/pipeline"
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
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { GripVertical, Loader2, Plus, Trash2 } from "lucide-react"

interface PipelineStagesManagerProps {
  pipelineId: number
  onStagesChanged?: () => void
}

const DEFAULT_NEW_COLOR = "#3B82F6"

/** "" deja la etapa sin probabilidad; cualquier otro valor se acota a 0..100. */
function parseProbability(raw: string): number | null {
  const digits = raw.replace(/\D/g, "")
  return digits === "" ? null : Math.min(100, Number(digits))
}

function SortableStageRow({
  stage,
  canManage,
  onNameChange,
  onColorChange,
  onProbabilityChange,
  onCommit,
  onDelete,
  saving,
}: {
  stage: PipelineStage
  canManage: boolean
  onNameChange: (id: number, name: string) => void
  onColorChange: (id: number, color: string) => void
  onProbabilityChange: (id: number, probability: number | null) => void
  onCommit: (stage: PipelineStage) => void
  onDelete: (stage: PipelineStage) => void
  saving: boolean
}) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: stage.id.toString(),
    disabled: !canManage,
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="flex items-center gap-2 rounded-md border border-border bg-card p-2"
    >
      {canManage && (
        <button
          type="button"
          className="cursor-grab rounded-sm text-muted-foreground touch-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
          aria-label={t("pipeline.stages.reorderAria", { name: stage.name })}
          {...attributes}
          {...listeners}
        >
          <GripVertical className="h-4 w-4" aria-hidden="true" />
        </button>
      )}

      <input
        type="color"
        value={stage.color || DEFAULT_NEW_COLOR}
        disabled={!canManage || saving}
        onChange={(e) => onColorChange(stage.id, e.target.value)}
        onBlur={() => onCommit(stage)}
        className="h-8 w-8 shrink-0 cursor-pointer rounded border border-border bg-transparent p-0.5 disabled:cursor-not-allowed"
        aria-label={t("pipeline.stages.colorAria", { name: stage.name })}
      />

      <Input
        value={stage.name}
        disabled={!canManage || saving}
        onChange={(e) => onNameChange(stage.id, e.target.value)}
        onBlur={() => onCommit(stage)}
        className="h-8 flex-1"
        aria-label={t("pipeline.stages.nameAria")}
      />

      <div className="relative w-[4.5rem] shrink-0">
        <Input
          inputMode="numeric"
          value={stage.win_probability ?? ""}
          placeholder="—"
          disabled={!canManage || saving}
          onChange={(e) => onProbabilityChange(stage.id, parseProbability(e.target.value))}
          onBlur={() => onCommit(stage)}
          className="h-8 pr-6 text-right tabular-nums"
          aria-label={t("pipeline.stages.probabilityAria", { name: stage.name })}
        />
        <span aria-hidden="true" className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">%</span>
      </div>

      {canManage && (
        <Button
          variant="ghost"
          size="sm"
          className="h-8 w-8 p-0 text-destructive hover:text-destructive"
          disabled={saving}
          onClick={() => onDelete(stage)}
          aria-label={t("pipeline.stages.deleteAria", { name: stage.name })}
        >
          <Trash2 className="h-4 w-4" aria-hidden="true" />
        </Button>
      )}
    </div>
  )
}

export function PipelineStagesManager({ pipelineId, onStagesChanged }: PipelineStagesManagerProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canManage = usePermission("pipeline_stages.manage")

  const [stages, setStages] = useState<PipelineStage[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)
  const [saving, setSaving] = useState(false)
  const [newName, setNewName] = useState("")
  const [newColor, setNewColor] = useState(DEFAULT_NEW_COLOR)
  const [stageToDelete, setStageToDelete] = useState<PipelineStage | null>(null)

  // Snapshot del valor guardado en backend, para evitar persistir si no hubo cambios.
  const savedValues = useRef<Map<number, Pick<PipelineStage, "name" | "color" | "win_probability">>>(new Map())

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )
  const dndAccessibility = useDndAccessibility((id) => {
    const name = stages.find((stage) => stage.id.toString() === String(id))?.name
    return name ? t("pipeline.stages.reorderTarget", { name }) : undefined
  })

  const fetchStages = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    try {
      const data = await fetchPipelineStages(pipelineId)
      const sorted = [...data].sort((a, b) => a.sort_order - b.sort_order)
      savedValues.current = new Map(sorted.map((s) => [s.id, { name: s.name, color: s.color, win_probability: s.win_probability }]))
      setStages(sorted)
    } catch {
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [pipelineId])

  useEffect(() => {
    fetchStages()
  }, [fetchStages])

  const handleNameChangeLocal = (id: number, name: string) => {
    setStages((prev) => prev.map((s) => (s.id === id ? { ...s, name } : s)))
  }

  const handleColorChangeLocal = (id: number, color: string) => {
    setStages((prev) => prev.map((s) => (s.id === id ? { ...s, color } : s)))
  }

  const handleProbabilityChangeLocal = (id: number, probability: number | null) => {
    setStages((prev) => prev.map((s) => (s.id === id ? { ...s, win_probability: probability } : s)))
  }

  const persistStage = async (stage: PipelineStage) => {
    const saved = savedValues.current.get(stage.id)
    const name = stage.name.trim()
    if (!name) {
      fetchStages()
      return
    }
    if (saved && saved.name === name && saved.color === stage.color && saved.win_probability === stage.win_probability) {
      return
    }

    // Solo los campos que cambiaron: el snapshot puede tener un nombre viejo (p.ej.
    // el backend renombró "Contactar <mes>") y mandarlo cortaría la plantilla.
    const changes: { name?: string; color?: string; win_probability?: number | null } = {}
    if (!saved || saved.name !== name) changes.name = name
    if (!saved || saved.color !== stage.color) changes.color = stage.color
    if (!saved || saved.win_probability !== stage.win_probability) changes.win_probability = stage.win_probability

    setSaving(true)
    try {
      const updated = await updatePipelineStage(stage.id, changes)
      savedValues.current.set(stage.id, { name: updated.name, color: updated.color, win_probability: updated.win_probability })
      setStages((prev) =>
        prev.map((s) => (s.id === updated.id ? { ...s, name: updated.name, color: updated.color, win_probability: updated.win_probability } : s))
      )
      onStagesChanged?.()
    } catch {
      addToast({
        type: "error",
        title: t("pipeline.stages.errorTitle"),
        description: t("pipeline.stages.errorUpdate"),
      })
      fetchStages()
    } finally {
      setSaving(false)
    }
  }

  const handleAdd = async () => {
    const name = newName.trim()
    if (!name) return

    setSaving(true)
    try {
      await createPipelineStage({ pipeline_id: pipelineId, name, color: newColor })
      setNewName("")
      setNewColor(DEFAULT_NEW_COLOR)
      await fetchStages()
      onStagesChanged?.()
      addToast({
        type: "success",
        title: t("pipeline.stages.createdTitle"),
        description: name,
      })
    } catch {
      addToast({
        type: "error",
        title: t("pipeline.stages.errorTitle"),
        description: t("pipeline.stages.errorCreate"),
      })
    } finally {
      setSaving(false)
    }
  }

  const handleConfirmDelete = async () => {
    if (!stageToDelete) return

    setSaving(true)
    try {
      await deletePipelineStage(stageToDelete.id)
      setStages((prev) => prev.filter((s) => s.id !== stageToDelete.id))
      onStagesChanged?.()
      addToast({
        type: "success",
        title: t("pipeline.stages.deletedTitle"),
        description: stageToDelete.name,
      })
    } catch (error) {
      addToast({
        type: "error",
        title: t("pipeline.stages.errorTitle"),
        description: error instanceof Error ? error.message : t("pipeline.stages.errorDelete"),
      })
    } finally {
      setSaving(false)
      setStageToDelete(null)
    }
  }

  const handleDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) return

    const oldIndex = stages.findIndex((s) => s.id.toString() === active.id)
    const newIndex = stages.findIndex((s) => s.id.toString() === over.id)
    if (oldIndex < 0 || newIndex < 0) return

    const reordered = arrayMove(stages, oldIndex, newIndex)
    setStages(reordered)

    const payload = reordered.map((s, index) => ({ id: s.id, sort_order: index + 1 }))
    setSaving(true)
    try {
      await reorderPipelineStages(pipelineId, payload)
      onStagesChanged?.()
    } catch {
      addToast({
        type: "error",
        title: t("pipeline.stages.errorTitle"),
        description: t("pipeline.stages.errorReorder"),
      })
      fetchStages()
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-8">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" aria-hidden="true" />
      </div>
    )
  }

  if (loadError) {
    return (
      <div role="alert" className="space-y-3 py-6 text-center">
        <p className="text-sm text-destructive">{t("pipeline.stages.errorLoad")}</p>
        <Button variant="outline" size="sm" onClick={() => void fetchStages()}>
          {t("pipeline.errors.retry")}
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <DndContext sensors={sensors} accessibility={dndAccessibility} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext
          items={stages.map((s) => s.id.toString())}
          strategy={verticalListSortingStrategy}
        >
          <div className="space-y-2">
            {stages.map((stage) => (
              <SortableStageRow
                key={stage.id}
                stage={stage}
                canManage={canManage}
                saving={saving}
                onNameChange={handleNameChangeLocal}
                onColorChange={handleColorChangeLocal}
                onProbabilityChange={handleProbabilityChangeLocal}
                onCommit={persistStage}
                onDelete={setStageToDelete}
              />
            ))}
          </div>
        </SortableContext>
      </DndContext>

      {stages.length === 0 && (
        <p className="py-4 text-center text-sm text-muted-foreground">
          {t("pipeline.stages.empty")}
        </p>
      )}

      {canManage && (
        <div className="flex items-center gap-2 border-t border-border pt-4">
          <input
            type="color"
            value={newColor}
            onChange={(e) => setNewColor(e.target.value)}
            className="h-9 w-9 shrink-0 cursor-pointer rounded border border-border bg-transparent p-0.5"
            aria-label={t("pipeline.stages.newColorAria")}
          />
          <Input
            value={newName}
            onChange={(e) => setNewName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") handleAdd()
            }}
            placeholder={t("pipeline.stages.namePlaceholder")}
            className="h-9 flex-1"
            disabled={saving}
          />
          <Button onClick={handleAdd} size="sm" className="h-9" disabled={saving || !newName.trim()}>
            {saving ? <Loader2 className="mr-1 h-4 w-4 animate-spin" aria-hidden="true" /> : <Plus className="mr-1 h-4 w-4" aria-hidden="true" />}
            {t("pipeline.stages.add")}
          </Button>
        </div>
      )}

      {canManage && stages.length > 0 && (
        <div className="space-y-1 text-xs text-muted-foreground">
          <p>{t("pipeline.stages.editHint")}</p>
          <p>{t("pipeline.stages.probabilityHint")}</p>
        </div>
      )}

      <AlertDialog open={stageToDelete !== null} onOpenChange={(open) => !open && setStageToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("pipeline.stages.deleteTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("pipeline.stages.deleteConfirm", { name: stageToDelete?.name ?? "" })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              // Queda abierto mientras borra: el cierre llega en el finally.
              onClick={(event) => {
                event.preventDefault()
                void handleConfirmDelete()
              }}
              disabled={saving}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />}
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
