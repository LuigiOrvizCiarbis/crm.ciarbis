"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import {
  getPipelineStages,
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

function SortableStageRow({
  stage,
  canManage,
  onNameChange,
  onColorChange,
  onCommit,
  onDelete,
  saving,
}: {
  stage: PipelineStage
  canManage: boolean
  onNameChange: (id: number, name: string) => void
  onColorChange: (id: number, color: string) => void
  onCommit: (stage: PipelineStage) => void
  onDelete: (stage: PipelineStage) => void
  saving: boolean
}) {
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
          className="cursor-grab active:cursor-grabbing text-muted-foreground touch-none"
          {...attributes}
          {...listeners}
        >
          <GripVertical className="h-4 w-4" />
        </button>
      )}

      <input
        type="color"
        value={stage.color || DEFAULT_NEW_COLOR}
        disabled={!canManage || saving}
        onChange={(e) => onColorChange(stage.id, e.target.value)}
        onBlur={() => onCommit(stage)}
        className="h-8 w-8 shrink-0 cursor-pointer rounded border border-border bg-transparent p-0.5 disabled:cursor-not-allowed"
        aria-label="color"
      />

      <Input
        value={stage.name}
        disabled={!canManage || saving}
        onChange={(e) => onNameChange(stage.id, e.target.value)}
        onBlur={() => onCommit(stage)}
        className="h-8 flex-1"
      />

      {canManage && (
        <Button
          variant="ghost"
          size="sm"
          className="h-8 w-8 p-0 text-destructive hover:text-destructive"
          disabled={saving}
          onClick={() => onDelete(stage)}
        >
          <Trash2 className="h-4 w-4" />
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
  const [saving, setSaving] = useState(false)
  const [newName, setNewName] = useState("")
  const [newColor, setNewColor] = useState(DEFAULT_NEW_COLOR)
  const [stageToDelete, setStageToDelete] = useState<PipelineStage | null>(null)

  // Snapshot del valor guardado en backend, para evitar persistir si no hubo cambios.
  const savedValues = useRef<Map<number, { name: string; color: string }>>(new Map())

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  const fetchStages = useCallback(async () => {
    setLoading(true)
    const data = await getPipelineStages(pipelineId)
    const sorted = [...data].sort((a, b) => a.sort_order - b.sort_order)
    savedValues.current = new Map(sorted.map((s) => [s.id, { name: s.name, color: s.color }]))
    setStages(sorted)
    setLoading(false)
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

  const persistStage = async (stage: PipelineStage) => {
    const saved = savedValues.current.get(stage.id)
    const name = stage.name.trim()
    if (!name) {
      fetchStages()
      return
    }
    if (saved && saved.name === name && saved.color === stage.color) {
      return
    }

    setSaving(true)
    try {
      await updatePipelineStage(stage.id, { name, color: stage.color })
      savedValues.current.set(stage.id, { name, color: stage.color })
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
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
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
            aria-label="color"
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
            <Plus className="mr-1 h-4 w-4" />
            {t("pipeline.stages.add")}
          </Button>
        </div>
      )}

      {canManage && stages.length > 0 && (
        <p className="text-xs text-muted-foreground">{t("pipeline.stages.editHint")}</p>
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
              onClick={handleConfirmDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
