"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { format } from "date-fns"
import { es } from "date-fns/locale"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import { useTaskStore } from "@/store/useTaskStore"
import type { Task, TaskPriority, TaskStatus } from "@/lib/types/task"
import { priorityLabels, statusLabels, typeLabels } from "./task-labels"

type EditableFields = Pick<Task, "name" | "status" | "priority" | "description" | "checklist">

interface TaskDetailSheetProps {
  task: Task | null
  onOpenChange: (open: boolean) => void
  /** Se llama cuando el back confirmó el cambio, para reflejarlo en la vista que abrió el panel. */
  onSaved?: (taskId: string, changes: EditableFields) => void
}

const relationLabels = { contact: "Contacto", pipeline: "Oportunidad", chat: "Chat" } as const

function formatDate(value?: string | null) {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : format(date, "d 'de' MMM yyyy, HH:mm", { locale: es })
}

/** Panel de detalle y edición rápida de una tarea (lista y Gantt). */
export function TaskDetailSheet({ task, onOpenChange, onSaved }: TaskDetailSheetProps) {
  const updateTask = useTaskStore((state) => state.updateTask)
  const { addToast } = useToast()
  const [draft, setDraft] = useState<EditableFields | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setDraft(
      task
        ? { name: task.name, status: task.status, priority: task.priority, description: task.description, checklist: task.checklist }
        : null,
    )
  }, [task])

  // Se cierra recién cuando el back confirma; si falla, el panel queda abierto con los cambios.
  const handleSave = () => {
    if (!task || !draft) return
    const taskId = task.id
    setSaving(true)
    updateTask(taskId, draft)
      .then(
        () => {
          onSaved?.(taskId, draft)
          addToast({ type: "success", title: "Tarea actualizada" })
          onOpenChange(false)
        },
        (error) => addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo actualizar la tarea" }),
      )
      .finally(() => setSaving(false))
  }

  const when = task?.type === "reunion" ? formatDate(task.startsAt) ?? formatDate(task.deadline) : formatDate(task?.deadline)

  return (
    <Sheet open={!!task} onOpenChange={(open) => { if (!saving) onOpenChange(open) }}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-[540px]">
        <SheetHeader>
          <SheetTitle>Detalle de la tarea</SheetTitle>
          <SheetDescription>{task ? `${typeLabels[task.type]} · ${statusLabels[task.status]}` : ""}</SheetDescription>
        </SheetHeader>

        {task && draft && (
          <div className="mt-6 space-y-4">
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg border border-border p-3 text-sm">
              <dt className="text-muted-foreground">{task.type === "reunion" ? "Inicio" : "Vencimiento"}</dt>
              <dd>{when ?? "Sin fecha"}</dd>
              <dt className="text-muted-foreground">Responsable</dt>
              <dd>{task.assignee || "Sin asignar"}</dd>
              <dt className="text-muted-foreground">Relacionado con</dt>
              <dd className="min-w-0 truncate">
                {task.relatedTo ? (
                  task.relatedTo.kind === "chat" ? (
                    <Link href={`/chats?chat=${task.relatedTo.id}`} className="text-primary underline-offset-2 hover:underline">
                      {relationLabels.chat} · {task.relatedTo.label}
                    </Link>
                  ) : (
                    `${relationLabels[task.relatedTo.kind]} · ${task.relatedTo.label}`
                  )
                ) : (
                  "Sin relación"
                )}
              </dd>
            </dl>

            <div className="space-y-2">
              <Label htmlFor="task-detail-name">Nombre</Label>
              <Input
                id="task-detail-name"
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="task-detail-status">Estado</Label>
                <Select value={draft.status} onValueChange={(value) => setDraft({ ...draft, status: value as TaskStatus })}>
                  <SelectTrigger id="task-detail-status">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {(Object.keys(statusLabels) as TaskStatus[]).map((status) => (
                      <SelectItem key={status} value={status}>
                        {statusLabels[status]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="task-detail-priority">Prioridad</Label>
                <Select value={draft.priority} onValueChange={(value) => setDraft({ ...draft, priority: value as TaskPriority })}>
                  <SelectTrigger id="task-detail-priority">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {(Object.keys(priorityLabels) as TaskPriority[]).map((priority) => (
                      <SelectItem key={priority} value={priority}>
                        {priorityLabels[priority]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="task-detail-description">Descripción</Label>
              <Textarea
                id="task-detail-description"
                value={draft.description || ""}
                onChange={(e) => setDraft({ ...draft, description: e.target.value })}
                rows={3}
              />
            </div>

            {draft.checklist.length > 0 && (
              <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Checklist</legend>
                {draft.checklist.map((item) => (
                  <div key={item.id} className="flex items-center gap-2">
                    <Checkbox
                      id={`task-detail-check-${item.id}`}
                      checked={item.done}
                      onCheckedChange={() =>
                        setDraft({
                          ...draft,
                          checklist: draft.checklist.map((entry) => (entry.id === item.id ? { ...entry, done: !entry.done } : entry)),
                        })
                      }
                    />
                    <label
                      htmlFor={`task-detail-check-${item.id}`}
                      className={`text-sm ${item.done ? "text-muted-foreground line-through" : "text-foreground"}`}
                    >
                      {item.text}
                    </label>
                  </div>
                ))}
              </fieldset>
            )}

            <div className="flex gap-2 pt-4">
              <Button onClick={handleSave} className="flex-1" disabled={saving || !draft.name.trim()} aria-busy={saving}>
                {saving ? "Guardando…" : "Guardar cambios"}
              </Button>
              <Button variant="outline" onClick={() => onOpenChange(false)} className="flex-1" disabled={saving}>
                Cancelar
              </Button>
            </div>
          </div>
        )}
      </SheetContent>
    </Sheet>
  )
}
