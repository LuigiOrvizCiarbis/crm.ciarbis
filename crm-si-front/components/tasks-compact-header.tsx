"use client"

import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Search, Filter, List, LayoutGrid, GanttChart, CalendarIcon, Check, Plus } from "lucide-react"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { cn } from "@/lib/utils"

interface TasksCompactHeaderProps {
  activeView: string
  onViewChange: (view: string) => void
  searchValue?: string
  onSearch?: (query: string) => void
  onNewTask?: () => void
  onFilterClick?: () => void
}

// Vistas para los selectores compactos (tablet y teléfono); desktop conserva su grupo con etiquetas.
const VIEWS = [
  { value: "lista", label: "Lista", icon: List },
  { value: "kanban", label: "Kanban", icon: LayoutGrid },
  { value: "gantt", label: "Gantt", icon: GanttChart, hint: "Se desplaza de costado en pantallas chicas" },
  { value: "calendario", label: "Calendario", icon: CalendarIcon },
] as const

export function TasksCompactHeader({
  activeView,
  onViewChange,
  searchValue,
  onSearch,
  onNewTask,
  onFilterClick,
}: TasksCompactHeaderProps) {
  const [viewSheetOpen, setViewSheetOpen] = useState(false)
  const currentView = VIEWS.find((view) => view.value === activeView) ?? VIEWS[0]
  const CurrentViewIcon = currentView.icon

  return (
    <div className="sticky top-0 z-40 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 border-b border-border">
      <div className="h-[80px] px-4 md:px-6 flex items-center gap-3">
        {/* Left: Title only (no subtitle) */}
        <div className="flex items-center gap-2 shrink-0">
          <h1 className="text-lg font-semibold tracking-tight">Gestión de tareas</h1>
        </div>

        {/* Center: Search input (flexible width) */}
        <div className="relative flex-1 max-w-md hidden md:block">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <Input placeholder="Buscar tareas..." aria-label="Buscar tareas" className="pl-9 h-9" value={searchValue} onChange={(e) => onSearch?.(e.target.value)} />
        </div>

        {/* View switcher (Lista | Kanban | Gantt | Calendario) */}
        <div className="hidden lg:block shrink-0">
          <ToggleGroup type="single" value={activeView} onValueChange={onViewChange} className="gap-1.5">
            <ToggleGroupItem value="lista" size="sm" className="gap-1.5">
              <List className="w-4 h-4" />
              Lista
            </ToggleGroupItem>
            <ToggleGroupItem value="kanban" size="sm" className="gap-1.5">
              <LayoutGrid className="w-4 h-4" />
              Kanban
            </ToggleGroupItem>
            <ToggleGroupItem value="gantt" size="sm" className="gap-1.5">
              <GanttChart className="w-4 h-4" />
              Gantt
            </ToggleGroupItem>
            <ToggleGroupItem value="calendario" size="sm" className="ml-1 gap-2 px-3.5">
              <CalendarIcon className="size-4 shrink-0" />
              Calendario
            </ToggleGroupItem>
          </ToggleGroup>
        </div>

        {/* Tablet: mismo selector en íconos de 40px; la etiqueta queda en aria-label y title. */}
        <div className="hidden md:block lg:hidden shrink-0">
          <ToggleGroup
            type="single"
            value={activeView}
            onValueChange={(value) => value && onViewChange(value)}
            className="gap-1"
            aria-label="Vista de tareas"
          >
            {VIEWS.map(({ value, label, icon: Icon }) => (
              <ToggleGroupItem key={value} value={value} className="size-10" aria-label={label} title={label}>
                <Icon className="size-4" aria-hidden="true" />
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        </div>

        {/* Right: Filters + CTA */}
        <div className="flex items-center gap-2 ml-auto shrink-0">
          {/* Teléfono: botón con el ícono de la vista actual que abre una hoja inferior. */}
          <Button
            variant="outline"
            size="icon"
            className="size-10 bg-transparent md:hidden"
            type="button"
            onClick={() => setViewSheetOpen(true)}
            aria-label={`Vista: ${currentView.label}. Cambiar vista`}
            aria-haspopup="dialog"
          >
            <CurrentViewIcon className="h-4 w-4" aria-hidden="true" />
          </Button>

          {/* Filters button */}
          <Button variant="outline" size="sm" onClick={onFilterClick} className="gap-2 bg-transparent max-md:w-10" type="button" aria-label="Filtros">
            <Filter className="w-4 h-4" />
            <span className="hidden md:inline">Filtros</span>
          </Button>

          {/* CTA primary with keyboard shortcut hint */}
          <Button size="sm" onClick={onNewTask} className="shrink-0 gap-2 max-md:size-10 max-md:p-0" aria-label="Nueva tarea">
            <span className="md:hidden">
              <Plus className="h-4 w-4" aria-hidden="true" />
            </span>
            <span className="hidden md:inline">+ Nueva tarea</span>
            <kbd className="hidden lg:inline-flex h-5 select-none items-center gap-1 rounded border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground opacity-100">
              N
            </kbd>
          </Button>
        </div>
      </div>

      <Sheet open={viewSheetOpen} onOpenChange={setViewSheetOpen}>
        <SheetContent side="bottom">
          <SheetHeader>
            <SheetTitle>Vista de tareas</SheetTitle>
            <SheetDescription>Elegí cómo ver tus tareas.</SheetDescription>
          </SheetHeader>
          <div className="flex flex-col gap-1 px-4 pb-4">
            {VIEWS.map((view) => {
              const Icon = view.icon
              const selected = view.value === activeView
              return (
                <button
                  key={view.value}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => {
                    onViewChange(view.value)
                    setViewSheetOpen(false)
                  }}
                  className={cn(
                    "flex min-h-12 items-center gap-3 rounded-md border px-3 py-2 text-left text-sm outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50",
                    selected ? "border-primary font-semibold text-primary" : "border-transparent",
                  )}
                >
                  <Icon className="size-5 shrink-0" aria-hidden="true" />
                  <span className="flex-1">
                    {view.label}
                    {"hint" in view && <span className="block text-xs font-normal text-muted-foreground">{view.hint}</span>}
                  </span>
                  {selected && <Check className="size-4 shrink-0" aria-hidden="true" />}
                </button>
              )
            })}
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
