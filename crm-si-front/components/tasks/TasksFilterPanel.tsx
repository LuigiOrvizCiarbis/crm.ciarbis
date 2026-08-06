"use client"

import type React from "react"

import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetFooter } from "@/components/ui/sheet"
import { Popover, PopoverContent } from "@/components/ui/popover"
import { Badge } from "@/components/ui/badge"
import { X } from "lucide-react"
import type { TaskStatus, TaskType } from "@/lib/types/task"

export interface TaskFilters {
  status: TaskStatus[]
  assignees: string[]
  types: TaskType[]
  deadline: "overdue" | "today" | "this-week" | "next-7-days" | "no-date" | null
}

interface TasksFilterPanelProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  filters: TaskFilters
  onFiltersChange: (filters: TaskFilters) => void
  trigger?: React.ReactNode
  isMobile?: boolean
}

const STATUS_OPTIONS: { value: TaskStatus; label: string }[] = [
  { value: "nuevo", label: "Nuevo" },
  { value: "en-curso", label: "En curso" },
  { value: "en-espera", label: "En espera" },
  { value: "reprogramado", label: "Reprogramado" },
  { value: "hecho", label: "Hecho" },
]

const TYPE_OPTIONS: { value: TaskType; label: string }[] = [
  { value: "reunion", label: "Reunión" },
  { value: "llamado", label: "Llamado" },
  { value: "demo", label: "Demo" },
  { value: "propuesta", label: "Propuesta" },
  { value: "soporte", label: "Soporte" },
  { value: "seguimiento", label: "Seguimiento" },
]

const ASSIGNEE_OPTIONS = ["Martín", "Valeria", "Lucas", "Sofia"]

const DEADLINE_OPTIONS: { value: TaskFilters["deadline"]; label: string }[] = [
  { value: "overdue", label: "Vencidas" },
  { value: "today", label: "Para hoy" },
  { value: "this-week", label: "Esta semana" },
  { value: "next-7-days", label: "Próximos 7 días" },
  { value: "no-date", label: "Sin fecha" },
]

export function TasksFilterPanel({
  open,
  onOpenChange,
  filters,
  onFiltersChange,
  trigger,
  isMobile = false,
}: TasksFilterPanelProps) {
  const [localFilters, setLocalFilters] = useState<TaskFilters>(filters)

  useState(() => {
    if (open) {
      console.log("[v0] Filters panel opened with filters:", filters)
      setLocalFilters(filters)
    }
  })

  const handleApply = () => {
    onFiltersChange(localFilters)
    onOpenChange(false)
  }

  const handleClear = () => {
    const emptyFilters: TaskFilters = {
      status: [],
      assignees: [],
      types: [],
      deadline: null,
    }
    setLocalFilters(emptyFilters)
    onFiltersChange(emptyFilters)
  }

  const toggleStatus = (status: TaskStatus) => {
    setLocalFilters((prev) => ({
      ...prev,
      status: prev.status.includes(status) ? prev.status.filter((s) => s !== status) : [...prev.status, status],
    }))
  }

  const toggleAssignee = (assignee: string) => {
    setLocalFilters((prev) => ({
      ...prev,
      assignees: prev.assignees.includes(assignee)
        ? prev.assignees.filter((a) => a !== assignee)
        : [...prev.assignees, assignee],
    }))
  }

  const toggleType = (type: TaskType) => {
    setLocalFilters((prev) => ({
      ...prev,
      types: prev.types.includes(type) ? prev.types.filter((t) => t !== type) : [...prev.types, type],
    }))
  }

  const setDeadline = (deadline: TaskFilters["deadline"]) => {
    setLocalFilters((prev) => ({
      ...prev,
      deadline: prev.deadline === deadline ? null : deadline,
    }))
  }

  const activeFiltersCount =
    localFilters.status.length +
    localFilters.assignees.length +
    localFilters.types.length +
    (localFilters.deadline ? 1 : 0)

  const FilterContent = () => (
    <div className="space-y-6">
      {/* Status Filter */}
      <div className="space-y-3">
        <Label className="text-sm font-semibold">Estado</Label>
        <div className="space-y-2">
          {STATUS_OPTIONS.map((option) => (
            <div key={option.value} className="flex items-center space-x-2">
              <Checkbox
                id={`status-${option.value}`}
                checked={localFilters.status.includes(option.value)}
                onCheckedChange={() => toggleStatus(option.value)}
              />
              <label
                htmlFor={`status-${option.value}`}
                className="text-sm font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
              >
                {option.label}
              </label>
            </div>
          ))}
        </div>
      </div>

      {/* Assignee Filter */}
      <div className="space-y-3">
        <Label className="text-sm font-semibold">Responsable</Label>
        <div className="space-y-2">
          {ASSIGNEE_OPTIONS.map((assignee) => (
            <div key={assignee} className="flex items-center space-x-2">
              <Checkbox
                id={`assignee-${assignee}`}
                checked={localFilters.assignees.includes(assignee)}
                onCheckedChange={() => toggleAssignee(assignee)}
              />
              <label
                htmlFor={`assignee-${assignee}`}
                className="text-sm font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
              >
                {assignee}
              </label>
            </div>
          ))}
        </div>
      </div>

      {/* Type Filter */}
      <div className="space-y-3">
        <Label className="text-sm font-semibold">Tipo</Label>
        <div className="space-y-2">
          {TYPE_OPTIONS.map((option) => (
            <div key={option.value} className="flex items-center space-x-2">
              <Checkbox
                id={`type-${option.value}`}
                checked={localFilters.types.includes(option.value)}
                onCheckedChange={() => toggleType(option.value)}
              />
              <label
                htmlFor={`type-${option.value}`}
                className="text-sm font-normal leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
              >
                {option.label}
              </label>
            </div>
          ))}
        </div>
      </div>

      {/* Date Filter */}
      <div className="space-y-3">
        <Label className="text-sm font-semibold">Fecha</Label>
        <div className="flex flex-wrap gap-2">
          {DEADLINE_OPTIONS.map((option) => (
            <Badge
              key={option.value || "none"}
              variant={localFilters.deadline === option.value ? "default" : "outline"}
              className="cursor-pointer"
              onClick={() => setDeadline(option.value)}
            >
              {option.label}
            </Badge>
          ))}
        </div>
      </div>
    </div>
  )

  // Mobile: Sheet
  if (isMobile) {
    return (
      <Sheet open={open} onOpenChange={onOpenChange}>
        {trigger}
        <SheetContent side="bottom" className="h-[90vh]">
          <SheetHeader>
            <SheetTitle>Filtros de tareas</SheetTitle>
            <SheetDescription>
              {activeFiltersCount > 0 ? `${activeFiltersCount} filtro(s) activo(s)` : "Selecciona los filtros"}
            </SheetDescription>
          </SheetHeader>
          <div className="py-6 overflow-y-auto max-h-[calc(90vh-180px)]">
            <FilterContent />
          </div>
          <SheetFooter className="flex gap-2">
            <Button variant="outline" onClick={handleClear} className="flex-1 bg-transparent">
              Limpiar filtros
            </Button>
            <Button onClick={handleApply} className="flex-1">
              Aplicar filtros
            </Button>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    )
  }

  // Desktop: Popover
  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      {trigger}
      <PopoverContent className="w-[360px] p-0" align="end" sideOffset={8}>
        <div className="p-4 border-b border-border">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="font-semibold text-sm">Filtros de tareas</h3>
              <p className="text-xs text-muted-foreground mt-0.5">
                {activeFiltersCount > 0 ? `${activeFiltersCount} filtro(s) activo(s)` : "Selecciona los filtros"}
              </p>
            </div>
            {activeFiltersCount > 0 && (
              <Button variant="ghost" size="sm" onClick={handleClear} className="h-8 px-2">
                <X className="h-4 w-4" />
              </Button>
            )}
          </div>
        </div>
        <div className="p-4 max-h-[500px] overflow-y-auto">
          <FilterContent />
        </div>
        <div className="p-4 border-t border-border flex gap-2">
          <Button variant="outline" onClick={handleClear} className="flex-1 bg-transparent">
            Limpiar
          </Button>
          <Button onClick={handleApply} className="flex-1">
            Aplicar
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
