"use client"

import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { GripVertical, Plus, Trash2, TriangleAlert } from "lucide-react"

import { Button } from "@/components/ui/button"
import { useDndAccessibility } from "@/hooks/useDndAccessibility"
import { useTranslation } from "@/hooks/useTranslation"
import type { GroupedErrors } from "@/lib/flow-builder/errors"
import { MAX_SCREENS } from "@/lib/flow-builder/limits"
import type { FlowScreen } from "@/lib/flow-builder/types"
import { cn } from "@/lib/utils"

interface ScreenListProps {
  screens: FlowScreen[]
  selectedIndex: number
  errors: GroupedErrors
  onSelect: (index: number) => void
  onAdd: () => void
  onRemove: (index: number) => void
  onMove: (from: number, to: number) => void
}

function screenHasErrors(screen: FlowScreen, errors: GroupedErrors): boolean {
  return (
    (screen.uid !== undefined && errors.screens[screen.uid] !== undefined) ||
    screen.blocks.some((block) => block.uid !== undefined && errors.blocks[block.uid] !== undefined)
  )
}

export function ScreenList({ screens, selectedIndex, errors, onSelect, onAdd, onRemove, onMove }: ScreenListProps) {
  const { t } = useTranslation()
  const ids = screens.map((screen) => screen.uid ?? "")

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const dndAccessibility = useDndAccessibility((id) => screens.find((screen) => screen.uid === id)?.title)

  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return
    const from = ids.indexOf(String(active.id))
    const to = ids.indexOf(String(over.id))
    if (from >= 0 && to >= 0) onMove(from, to)
  }

  return (
    <div className="space-y-3">
      <h2 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {t("flows.builder.screens.title")}
      </h2>
      <DndContext sensors={sensors} accessibility={dndAccessibility} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={ids} strategy={verticalListSortingStrategy}>
          <ul className="space-y-1">
            {screens.map((screen, index) => (
              <SortableScreen
                key={screen.uid}
                screen={screen}
                index={index}
                selected={index === selectedIndex}
                hasErrors={screenHasErrors(screen, errors)}
                canRemove={screens.length > 1}
                onSelect={() => onSelect(index)}
                onRemove={() => onRemove(index)}
              />
            ))}
          </ul>
        </SortableContext>
      </DndContext>
      <Button variant="outline" size="sm" className="w-full border-dashed" onClick={onAdd} disabled={screens.length >= MAX_SCREENS}>
        <Plus className="size-4" />
        {t("flows.builder.screens.add")}
      </Button>
      {screens.length >= MAX_SCREENS ? (
        <p className="text-xs text-muted-foreground">{t("flows.builder.screens.max", { max: MAX_SCREENS })}</p>
      ) : null}
    </div>
  )
}

interface SortableScreenProps {
  screen: FlowScreen
  index: number
  selected: boolean
  hasErrors: boolean
  canRemove: boolean
  onSelect: () => void
  onRemove: () => void
}

function SortableScreen({ screen, index, selected, hasErrors, canRemove, onSelect, onRemove }: SortableScreenProps) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: screen.uid ?? "" })

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "group flex items-center gap-1.5 rounded-md border px-1.5 py-1.5 transition-colors",
        selected ? "border-primary/40 bg-primary/5" : "border-transparent hover:bg-muted/60",
        isDragging && "relative z-10 bg-background opacity-80 shadow-md",
      )}
    >
      <button
        type="button"
        className="cursor-grab text-muted-foreground/50 transition-colors hover:text-muted-foreground active:cursor-grabbing"
        aria-label={t("flows.builder.screens.reorder")}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="size-4" />
      </button>
      <button
        type="button"
        className="flex min-w-0 flex-1 items-center gap-2 text-left"
        onClick={onSelect}
        aria-current={selected ? "step" : undefined}
      >
        <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted font-mono text-[11px] text-muted-foreground">
          {index + 1}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium text-foreground">
            {screen.title || t("flows.builder.screens.untitled")}
          </span>
          <span className="block text-xs text-muted-foreground">
            {t("flows.builder.screens.blockCount", { count: screen.blocks.length })}
          </span>
        </span>
        {hasErrors ? (
          <TriangleAlert className="size-4 shrink-0 text-destructive" aria-label={t("flows.builder.screens.hasErrors")} />
        ) : null}
      </button>
      {canRemove ? (
        <Button
          variant="ghost"
          size="icon"
          className="size-7 shrink-0 text-muted-foreground opacity-60 hover:text-destructive group-hover:opacity-100"
          onClick={onRemove}
          aria-label={t("flows.builder.screens.remove")}
        >
          <Trash2 className="size-3.5" />
        </Button>
      ) : null}
    </li>
  )
}
