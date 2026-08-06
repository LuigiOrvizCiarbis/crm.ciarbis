"use client"

import type React from "react"

import { useState, useEffect } from "react"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import {
  CalendarIcon,
  Phone,
  Video,
  FileText,
  MapPin,
  HeadphonesIcon,
  Eye,
  Edit2,
  Trash2,
  Check,
  X,
  ExternalLink,
  GripVertical,
} from "lucide-react"
import type { Task } from "@/lib/types/task"
import { format } from "date-fns"
import { toast } from "sonner"
import { useRouter } from "next/navigation"
import { useTaskStore } from "@/store/useTaskStore"
import { CalendarSyncBadge } from "./CalendarSyncBadge"
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import {
  arrayMove,
  SortableContext,
  horizontalListSortingStrategy,
  sortableKeyboardCoordinates,
  useSortable,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"

const taskTypeIcons = {
  reunion: Video,
  llamado: Phone,
  demo: Video,
  propuesta: FileText,
  visita: MapPin,
  seguimiento: CalendarIcon,
  soporte: HeadphonesIcon,
}

const statusColors = {
  nuevo: "bg-blue-500/20 text-blue-400 border-blue-500/30",
  "en-curso": "bg-yellow-500/20 text-yellow-400 border-yellow-500/30",
  "en-espera": "bg-orange-500/20 text-orange-400 border-orange-500/30",
  reprogramado: "bg-purple-500/20 text-purple-400 border-purple-500/30",
  bloqueado: "bg-red-500/20 text-red-400 border-red-500/30",
  hecho: "bg-emerald-500/20 text-emerald-400 border-emerald-500/30",
  cancelado: "bg-gray-500/20 text-gray-400 border-gray-500/30",
}

const statusLabels: Record<Task["status"], string> = {
  nuevo: "Nuevo",
  "en-curso": "En curso",
  "en-espera": "En espera",
  reprogramado: "Reprogramado",
  bloqueado: "Bloqueado",
  hecho: "Hecho",
  cancelado: "Cancelado",
}

const priorityColors = {
  baja: "bg-gray-500/20 text-gray-300",
  media: "bg-blue-500/20 text-blue-300",
  alta: "bg-orange-500/20 text-orange-300",
  critica: "bg-red-500/20 text-red-300",
}

type TaskColumnKey =
  | "selection"
  | "name"
  | "status"
  | "deadline"
  | "priority"
  | "type"
  | "assignee"
  | "related"
  | "actions"

const TASK_COLUMN_WIDTHS_KEY = "tasks-table-column-widths"
const TASK_COLUMN_ORDER_KEY = "tasks-table-column-order"

const defaultColumnWidths: Record<TaskColumnKey, number> = {
  selection: 56,
  name: 320,
  status: 160,
  deadline: 150,
  priority: 120,
  type: 130,
  assignee: 180,
  related: 240,
  actions: 140,
}

const minColumnWidths: Record<TaskColumnKey, number> = {
  selection: 48,
  name: 220,
  status: 145,
  deadline: 130,
  priority: 105,
  type: 110,
  assignee: 140,
  related: 160,
  actions: 120,
}

interface TaskColumn {
  key: TaskColumnKey
  label: string
  draggable: boolean
}

const defaultTaskColumns: TaskColumn[] = [
  { key: "selection", label: "", draggable: false },
  { key: "name", label: "Nombre de tarea", draggable: true },
  { key: "related", label: "Relacionado con", draggable: true },
  { key: "assignee", label: "Responsable", draggable: true },
  { key: "deadline", label: "Fecha y hora", draggable: true },
  { key: "status", label: "Estado", draggable: true },
  { key: "priority", label: "Prioridad", draggable: true },
  { key: "type", label: "Tipo", draggable: true },
  { key: "actions", label: "Acciones", draggable: true },
]

const loadColumnOrder = (): TaskColumn[] => {
  if (typeof window === "undefined") return defaultTaskColumns

  try {
    const saved = window.localStorage.getItem(TASK_COLUMN_ORDER_KEY)
    if (!saved) return defaultTaskColumns

    const keys = JSON.parse(saved) as TaskColumnKey[]
    const ordered = keys
      .map((key) => defaultTaskColumns.find((column) => column.key === key))
      .filter((column): column is TaskColumn => Boolean(column))
    const missing = defaultTaskColumns.filter((column) => !ordered.some((item) => item.key === column.key))

    return [...ordered, ...missing]
  } catch {
    return defaultTaskColumns
  }
}

const getInitialColumnWidths = () => {
  if (typeof window === "undefined") return defaultColumnWidths

  try {
    const saved = window.localStorage.getItem(TASK_COLUMN_WIDTHS_KEY)
    if (!saved) return defaultColumnWidths

    return {
      ...defaultColumnWidths,
      ...JSON.parse(saved),
    }
  } catch {
    return defaultColumnWidths
  }
}

function SortableTaskHeader({
  column,
  width,
  minWidth,
  onResizeStart,
  children,
}: {
  column: TaskColumn
  width: number
  minWidth: number
  onResizeStart: (columnKey: TaskColumnKey, event: React.MouseEvent<HTMLButtonElement>) => void
  children?: React.ReactNode
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: column.key,
    disabled: !column.draggable,
  })

  return (
    <TableHead
      ref={setNodeRef}
      className="group relative select-none overflow-hidden pr-5"
      style={{
        width,
        minWidth,
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.55 : 1,
      }}
    >
      <div className="flex min-w-0 items-center gap-1.5">
        {column.draggable && (
          <button
            type="button"
            aria-label={`Mover columna ${column.label}`}
            className="cursor-grab touch-none text-muted-foreground/60 hover:text-foreground active:cursor-grabbing"
            {...attributes}
            {...listeners}
          >
            <GripVertical className="h-3.5 w-3.5" />
          </button>
        )}
        <div className="truncate">{children ?? column.label}</div>
      </div>
      <button
        type="button"
        aria-label={`Redimensionar columna ${column.label || "seleccion"}`}
        className="absolute right-0 top-0 h-full w-2 cursor-col-resize border-r border-transparent transition-colors hover:border-primary/70 group-hover:border-border"
        onMouseDown={(event) => onResizeStart(column.key, event)}
      />
    </TableHead>
  )
}

export function TaskListView({ tasks }: { tasks: Task[] }) {
  const updateTask = useTaskStore((state) => state.updateTask)
  const deleteTask = useTaskStore((state) => state.deleteTask)
  const [editingCell, setEditingCell] = useState<{ taskId: string; field: string } | null>(null)
  const [editValue, setEditValue] = useState("")
  const [localTasks, setLocalTasks] = useState(tasks)
  const [selectedTasks, setSelectedTasks] = useState<Set<string>>(new Set())
  const router = useRouter()
  const [showBulkEdit, setShowBulkEdit] = useState(false)
  const [bulkField, setBulkField] = useState<"status" | "priority">("status")
  const [bulkValue, setBulkValue] = useState("")
  const [columnWidths, setColumnWidths] = useState<Record<TaskColumnKey, number>>(getInitialColumnWidths)
  const [columns, setColumns] = useState<TaskColumn[]>(defaultTaskColumns)

  useEffect(() => {
    setColumns(loadColumnOrder())
  }, [])

  useEffect(() => {
    window.localStorage.setItem(TASK_COLUMN_ORDER_KEY, JSON.stringify(columns.map((column) => column.key)))
  }, [columns])

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const handleColumnDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return

    setColumns((current) => {
      const oldIndex = current.findIndex((column) => column.key === active.id)
      const newIndex = current.findIndex((column) => column.key === over.id)
      if (oldIndex === -1 || newIndex === -1) return current
      if (!current[oldIndex].draggable || !current[newIndex].draggable) return current

      return arrayMove(current, oldIndex, newIndex)
    })
  }

  useEffect(() => {
    window.localStorage.setItem(TASK_COLUMN_WIDTHS_KEY, JSON.stringify(columnWidths))
  }, [columnWidths])

  useEffect(() => {
    setLocalTasks(tasks)
    setSelectedTasks(new Set())
  }, [tasks])

  // Autosave with debounce
  useEffect(() => {
    const timeoutId = setTimeout(() => {
      if (editingCell) {
        handleSave()
      }
    }, 600)

    return () => clearTimeout(timeoutId)
  }, [editValue])

  const handleEdit = (taskId: string, field: string, currentValue: string) => {
    setEditingCell({ taskId, field })
    setEditValue(currentValue)
  }

  const handleSave = () => {
    if (!editingCell) return

    const { taskId, field } = editingCell

    // Optimistic update
    setLocalTasks((prev) =>
      prev.map((task) =>
        task.id === taskId
          ? {
              ...task,
              [field]: editValue,
              updatedAt: new Date().toISOString(),
            }
          : task,
      ),
    )

    setEditingCell(null)
    updateTask(taskId, { [field]: editValue }).then(
      () => toast.success("Tarea actualizada"),
      (error) => toast.error(error instanceof Error ? error.message : "No se pudo actualizar la tarea"),
    )
  }

  const handleCancel = () => {
    setEditingCell(null)
    setEditValue("")
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter") {
      handleSave()
    } else if (e.key === "Escape") {
      handleCancel()
    }
  }

  const handleStatusChange = (taskId: string, newStatus: Task["status"]) => {
    setLocalTasks((prev) =>
      prev.map((task) =>
        task.id === taskId
          ? {
              ...task,
              status: newStatus,
              completedAt: newStatus === "hecho" ? new Date().toISOString() : task.completedAt,
              updatedAt: new Date().toISOString(),
            }
          : task,
      ),
    )
    updateTask(taskId, {
      status: newStatus,
      completed_at: newStatus === "hecho" ? new Date().toISOString() : null,
    }).then(
      () => toast.success(`Estado cambiado a "${newStatus}"`),
      (error) => toast.error(error instanceof Error ? error.message : "No se pudo actualizar el estado"),
    )
  }

  const handleMarkDone = (taskId: string) => {
    handleStatusChange(taskId, "hecho")
  }

  const handleRelatedClick = (relation: Task["relatedTo"]) => {
    if (!relation) return

    if (relation.kind === "contact") {
      router.push(`/contactos?id=${relation.id}`)
    } else if (relation.kind === "pipeline") {
      router.push(`/oportunidades?id=${relation.id}`)
    } else if (relation.kind === "chat") {
      router.push(`/chats?chat=${relation.id}`)
    }
  }

  const toggleSelection = (taskId: string) => {
    setSelectedTasks((prev) => {
      const newSet = new Set(prev)
      if (newSet.has(taskId)) {
        newSet.delete(taskId)
      } else {
        newSet.add(taskId)
      }
      return newSet
    })
  }

  const toggleSelectAll = () => {
    if (selectedTasks.size === localTasks.length) {
      setSelectedTasks(new Set())
    } else {
      setSelectedTasks(new Set(localTasks.map((t) => t.id)))
    }
  }

  const isOverdue = (deadline?: string) => {
    if (!deadline) return false
    return new Date(deadline) < new Date()
  }

  const handleBulkEdit = () => {
    if (selectedTasks.size === 0) return

    setLocalTasks((prev) =>
      prev.map((task) => {
        if (selectedTasks.has(task.id)) {
          return {
            ...task,
            [bulkField]: bulkValue,
            updatedAt: new Date().toISOString(),
          }
        }
        return task
      }),
    )

    Promise.all(
      Array.from(selectedTasks).map((taskId) =>
        updateTask(taskId, {
          [bulkField]: bulkValue,
        }),
      ),
    ).then(
      () => undefined,
      (error) => toast.error(error instanceof Error ? error.message : "No se pudieron actualizar las tareas"),
    )

    toast.success(`${selectedTasks.size} tarea(s) actualizadas`, {
      action: {
        label: "Deshacer",
        onClick: () => {
          setLocalTasks(tasks)
          toast.success("Cambios revertidos")
        },
      },
      duration: 5000,
    })

    setShowBulkEdit(false)
    setSelectedTasks(new Set())
  }

  const handleBulkMarkDone = () => {
    if (selectedTasks.size === 0) return

    setLocalTasks((prev) =>
      prev.map((task) => {
        if (selectedTasks.has(task.id)) {
          return {
            ...task,
            status: "hecho",
            completedAt: new Date().toISOString(),
            updatedAt: new Date().toISOString(),
          }
        }
        return task
      }),
    )

    Promise.all(
      Array.from(selectedTasks).map((taskId) =>
        updateTask(taskId, {
          status: "hecho",
          completed_at: new Date().toISOString(),
        }),
      ),
    ).catch((error) => toast.error(error instanceof Error ? error.message : "No se pudieron completar las tareas"))

    toast.success(`${selectedTasks.size} tarea(s) marcadas como completadas`)
    setSelectedTasks(new Set())
  }

  const handleDelete = (taskId: string) => {
    deleteTask(taskId).then(
      () => toast.success("Tarea eliminada"),
      (error) => toast.error(error instanceof Error ? error.message : "No se pudo eliminar la tarea"),
    )
  }

  const handleColumnResizeStart = (columnKey: TaskColumnKey, event: React.MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()

    const startX = event.clientX
    const startWidth = columnWidths[columnKey]

    const handleMouseMove = (moveEvent: MouseEvent) => {
      const nextWidth = Math.max(minColumnWidths[columnKey], startWidth + moveEvent.clientX - startX)
      setColumnWidths((current) => ({
        ...current,
        [columnKey]: nextWidth,
      }))
    }

    const handleMouseUp = () => {
      document.body.style.cursor = ""
      document.body.style.userSelect = ""
      window.removeEventListener("mousemove", handleMouseMove)
      window.removeEventListener("mouseup", handleMouseUp)
    }

    document.body.style.cursor = "col-resize"
    document.body.style.userSelect = "none"
    window.addEventListener("mousemove", handleMouseMove)
    window.addEventListener("mouseup", handleMouseUp)
  }

  const tableWidth = columns.reduce((total, column) => total + columnWidths[column.key], 0)

  const renderResizableHeader = (column: TaskColumn, content?: React.ReactNode) => (
    <SortableTaskHeader
      key={column.key}
      column={column}
      width={columnWidths[column.key]}
      minWidth={minColumnWidths[column.key]}
      onResizeStart={handleColumnResizeStart}
    >
      {content}
    </SortableTaskHeader>
  )

  const renderTaskCell = (task: Task, columnKey: TaskColumnKey) => {
    const TypeIcon = taskTypeIcons[task.type]
    const isEditing = editingCell?.taskId === task.id

    switch (columnKey) {
      case "selection":
        return <Checkbox checked={selectedTasks.has(task.id)} onCheckedChange={() => toggleSelection(task.id)} />
      case "name":
        return isEditing && editingCell.field === "name" ? (
          <div className="flex items-center gap-1">
            <Input
              value={editValue}
              onChange={(event) => setEditValue(event.target.value)}
              onKeyDown={handleKeyDown}
              className="h-8 text-sm"
              autoFocus
            />
            <Button size="icon" variant="ghost" className="h-7 w-7" onClick={handleSave}>
              <Check className="h-4 w-4 text-emerald-500" />
            </Button>
            <Button size="icon" variant="ghost" className="h-7 w-7" onClick={handleCancel}>
              <X className="h-4 w-4 text-red-500" />
            </Button>
          </div>
        ) : (
          <div className="space-y-1">
            <div className="group flex items-center gap-2">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <TypeIcon className="h-4 w-4" />
              </span>
              <span className="font-medium text-foreground">{task.name}</span>
              <Button
                size="icon"
                variant="ghost"
                className="h-6 w-6 opacity-0 group-hover:opacity-100"
                onClick={() => handleEdit(task.id, "name", task.name)}
              >
                <Edit2 className="h-3 w-3" />
              </Button>
            </div>
            {task.type === "reunion" && <CalendarSyncBadge taskId={task.id} sync={task.calendarSync} />}
          </div>
        )
      case "related":
        return task.relatedTo ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 max-w-full gap-1 hover:bg-primary/10"
                onClick={() => handleRelatedClick(task.relatedTo)}
              >
                <span className="truncate">{task.relatedTo.label}</span>
                <ExternalLink className="h-3 w-3 shrink-0" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>
              Ver {task.relatedTo.kind === "contact" ? "contacto" : task.relatedTo.kind === "pipeline" ? "oportunidad" : "chat"}
            </TooltipContent>
          </Tooltip>
        ) : (
          <span className="text-xs text-muted-foreground">Sin relación</span>
        )
      case "assignee":
        return (
          <div className="flex items-center gap-2">
            <Avatar className="h-6 w-6">
              <AvatarFallback className="bg-primary/20 text-xs text-primary">
                {task.assignee.split(" ").map((part) => part[0]).join("").slice(0, 2)}
              </AvatarFallback>
            </Avatar>
            <span className="truncate text-sm text-foreground">{task.assignee}</span>
          </div>
        )
      case "deadline":
        return task.deadline ? (
          <div className={isOverdue(task.deadline) && task.status !== "hecho" ? "text-sm font-semibold text-red-400" : "text-sm text-muted-foreground"}>
            <div className="font-medium tabular-nums">{format(new Date(task.deadline), "dd/MM/yyyy")}</div>
            <div className="text-xs tabular-nums opacity-80">{format(new Date(task.deadline), "HH:mm")} hs</div>
          </div>
        ) : (
          <span className="text-xs text-muted-foreground">Sin fecha</span>
        )
      case "status":
        return (
          <Select value={task.status} onValueChange={(value) => handleStatusChange(task.id, value as Task["status"])}>
            <SelectTrigger className={`h-8 min-w-[130px] justify-between text-xs font-semibold ${statusColors[task.status]}`}>
              <SelectValue>{statusLabels[task.status]}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="nuevo">Nuevo</SelectItem>
              <SelectItem value="en-curso">En curso</SelectItem>
              <SelectItem value="en-espera">En espera</SelectItem>
              <SelectItem value="reprogramado">Reprogramado</SelectItem>
              <SelectItem value="bloqueado">Bloqueado</SelectItem>
              <SelectItem value="hecho">Hecho</SelectItem>
              <SelectItem value="cancelado">Cancelado</SelectItem>
            </SelectContent>
          </Select>
        )
      case "priority":
        return (
          <Badge variant="outline" className={`text-xs ${priorityColors[task.priority]}`}>
            {task.priority.charAt(0).toUpperCase() + task.priority.slice(1)}
          </Badge>
        )
      case "type":
        return (
          <Badge variant="outline" className="bg-muted/50 text-xs">
            {task.type.charAt(0).toUpperCase() + task.type.slice(1)}
          </Badge>
        )
      case "actions":
        return (
          <div className="flex items-center gap-1">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => handleMarkDone(task.id)} disabled={task.status === "hecho"}>
                  <Check className="h-4 w-4 text-emerald-500" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Marcar como Hecho</TooltipContent>
            </Tooltip>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button size="icon" variant="ghost" className="h-7 w-7">
                  <Eye className="h-4 w-4 text-blue-400" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Ver detalles</TooltipContent>
            </Tooltip>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => handleDelete(task.id)}>
                  <Trash2 className="h-4 w-4 text-red-400" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Eliminar</TooltipContent>
            </Tooltip>
          </div>
        )
    }
  }

  return (
    <TooltipProvider>
      <div className="rounded-lg border border-border bg-card">
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleColumnDragEnd}>
          <Table className="table-fixed" style={{ width: tableWidth }}>
            <colgroup>
              {columns.map((column) => (
                <col key={column.key} style={{ width: columnWidths[column.key] }} />
              ))}
            </colgroup>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <SortableContext items={columns.map((column) => column.key)} strategy={horizontalListSortingStrategy}>
                  {columns.map((column) =>
                    renderResizableHeader(
                      column,
                      column.key === "selection" ? (
                        <Checkbox
                          checked={selectedTasks.size === localTasks.length && localTasks.length > 0}
                          onCheckedChange={toggleSelectAll}
                        />
                      ) : undefined,
                    ),
                  )}
                </SortableContext>
              </TableRow>
            </TableHeader>
            <TableBody>
              {localTasks.map((task) => (
                <TableRow key={task.id} className="border-b border-border/50 hover:bg-muted/30">
                  {columns.map((column) => (
                    <TableCell key={column.key}>{renderTaskCell(task, column.key)}</TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </DndContext>

        {selectedTasks.size > 0 && (
          <div className="border-t border-border p-3 bg-muted/30 flex items-center justify-between">
            <span className="text-sm text-muted-foreground">{selectedTasks.size} tarea(s) seleccionada(s)</span>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" onClick={() => setShowBulkEdit(true)}>
                Editar en lote
              </Button>
              <Button size="sm" variant="outline" onClick={handleBulkMarkDone}>
                Marcar Hecho
              </Button>
              <Button size="sm" variant="outline" onClick={() => setSelectedTasks(new Set())}>
                Cancelar
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Bulk Edit Modal */}
      <Dialog open={showBulkEdit} onOpenChange={setShowBulkEdit}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Editar en lote</DialogTitle>
            <DialogDescription>Aplicar cambios a {selectedTasks.size} tarea(s) seleccionada(s)</DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label>Campo a editar</Label>
              <Select value={bulkField} onValueChange={(val) => setBulkField(val as typeof bulkField)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="status">Estado</SelectItem>
                  <SelectItem value="priority">Prioridad</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Nuevo valor</Label>
              {bulkField === "status" && (
                <Select value={bulkValue} onValueChange={setBulkValue}>
                  <SelectTrigger>
                    <SelectValue placeholder="Seleccionar estado" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="nuevo">Nuevo</SelectItem>
                    <SelectItem value="en-curso">En curso</SelectItem>
                    <SelectItem value="en-espera">En espera</SelectItem>
                    <SelectItem value="hecho">Hecho</SelectItem>
                  </SelectContent>
                </Select>
              )}

              {bulkField === "priority" && (
                <Select value={bulkValue} onValueChange={setBulkValue}>
                  <SelectTrigger>
                    <SelectValue placeholder="Seleccionar prioridad" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="baja">Baja</SelectItem>
                    <SelectItem value="media">Media</SelectItem>
                    <SelectItem value="alta">Alta</SelectItem>
                    <SelectItem value="critica">Crítica</SelectItem>
                  </SelectContent>
                </Select>
              )}

            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowBulkEdit(false)}>
              Cancelar
            </Button>
            <Button onClick={handleBulkEdit} disabled={!bulkValue}>
              Aplicar cambios
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </TooltipProvider>
  )
}
