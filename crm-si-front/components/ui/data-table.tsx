"use client"

import * as React from "react"
import { ArrowDown, ArrowUp, ArrowUpDown, GripVertical, Loader2 } from "lucide-react"

import { cn } from "@/lib/utils"
import { Checkbox } from "@/components/ui/checkbox"
import { useDndAccessibility } from "@/hooks/useDndAccessibility"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
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
  horizontalListSortingStrategy,
  sortableKeyboardCoordinates,
  useSortable,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"

/**
 * Tabla de datos transversal del proyecto.
 *
 * Todas las features son opt-in vía props, para que una sección simple
 * (catálogo) y una compleja (contactos) usen el mismo componente sin cargar
 * con lógica que no necesitan. Pensado para consumirse pasando `columns`
 * (config + render) y `data`.
 */

export type SortDirection = "asc" | "desc"

export interface DataTableSort {
  columnId: string
  direction: SortDirection
}

export interface DataTableColumn<T> {
  /** Identificador único de la columna. */
  id: string
  /** Encabezado. String o nodo. */
  header: React.ReactNode
  /** Render de la celda para una fila. */
  cell: (row: T) => React.ReactNode
  /** Si es ordenable. Requiere `onSortChange` en la tabla. */
  sortable?: boolean
  /** Si puede reordenarse por drag. Requiere `reorderable` en la tabla. */
  draggable?: boolean
  width?: string
  minWidth?: string
  /** Alinea contenido de header y celda a la derecha (acciones, montos). */
  align?: "left" | "right"
  className?: string
  headerClassName?: string
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  data: T[]
  /** Clave estable por fila. */
  getRowId: (row: T) => string | number

  loading?: boolean
  loadingLabel?: React.ReactNode
  emptyLabel?: React.ReactNode

  /** Filas zebra (alterna bg-muted/30). Default true. */
  zebra?: boolean
  /** Header pegado al hacer scroll. Default true. */
  stickyHeader?: boolean
  /** min-width del `<table>` para forzar scroll horizontal. */
  minTableWidth?: string
  className?: string

  onRowClick?: (row: T) => void

  // --- Ordenamiento (controlado) ---
  sort?: DataTableSort | null
  onSortChange?: (sort: DataTableSort) => void

  // --- Selección + bulk (controlado) ---
  selectable?: boolean
  selectedIds?: Set<string | number>
  onSelectedIdsChange?: (ids: Set<string | number>) => void

  // --- Reordenar columnas por drag (controlado) ---
  reorderable?: boolean
  onColumnOrderChange?: (orderedIds: string[]) => void
}

function SortIndicator({ active, direction }: { active: boolean; direction: SortDirection }) {
  const Icon = active ? (direction === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown
  return <Icon className="h-3.5 w-3.5 shrink-0" />
}

function HeaderCell<T>({
  column,
  sort,
  onSortChange,
  reorderable,
}: {
  column: DataTableColumn<T>
  sort?: DataTableSort | null
  onSortChange?: (sort: DataTableSort) => void
  reorderable?: boolean
}) {
  const draggable = Boolean(reorderable && column.draggable)
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
    id: column.id,
    disabled: !draggable,
  })

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    width: column.width,
    minWidth: column.minWidth,
  }

  const isSorted = sort?.columnId === column.id
  const direction = isSorted ? sort!.direction : "asc"

  const handleSort = () => {
    if (!column.sortable || !onSortChange) return
    onSortChange({
      columnId: column.id,
      direction: isSorted && direction === "asc" ? "desc" : "asc",
    })
  }

  const headerLabel = typeof column.header === "string" ? column.header : column.id

  // El <th> conserva su rol de encabezado: el mouse arrastra desde toda la celda y el
  // control accesible (rol, foco, teclado) es el botón del asa.
  return (
    <TableHead
      ref={setNodeRef}
      style={style}
      className={cn(
        column.align === "right" && "text-right",
        draggable && "select-none cursor-grab active:cursor-grabbing",
        column.headerClassName,
      )}
      aria-sort={isSorted ? (direction === "asc" ? "ascending" : "descending") : undefined}
      {...(draggable ? listeners : {})}
    >
      <div className={cn("flex items-center gap-2", column.align === "right" && "justify-end")}>
        {draggable && (
          <button
            type="button"
            ref={setActivatorNodeRef}
            {...attributes}
            aria-label={`Mover columna ${headerLabel}`}
            className="-m-1 rounded-sm p-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <GripVertical className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        )}
        {column.sortable && onSortChange ? (
          <button
            type="button"
            className={cn(
              "inline-flex min-w-0 items-center gap-1.5 rounded px-1 py-0.5 text-left hover:bg-muted",
              isSorted && "text-primary",
            )}
            onPointerDown={(e) => e.stopPropagation()}
            onClick={(e) => {
              e.stopPropagation()
              handleSort()
            }}
          >
            <span className="truncate">{column.header}</span>
            <SortIndicator active={isSorted} direction={direction} />
          </button>
        ) : (
          <span className="truncate">{column.header}</span>
        )}
      </div>
    </TableHead>
  )
}

export function DataTable<T>({
  columns,
  data,
  getRowId,
  loading = false,
  loadingLabel,
  emptyLabel,
  zebra = true,
  stickyHeader = true,
  minTableWidth,
  className,
  onRowClick,
  sort,
  onSortChange,
  selectable = false,
  selectedIds,
  onSelectedIdsChange,
  reorderable = false,
  onColumnOrderChange,
}: DataTableProps<T>) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const dndAccessibility = useDndAccessibility((id) => {
    const column = columns.find((item) => item.id === id)
    if (!column) return undefined
    return `la columna ${typeof column.header === "string" ? column.header : column.id}`
  })

  const selected = selectedIds ?? new Set<string | number>()
  const totalColumns = columns.length + (selectable ? 1 : 0)

  const allSelected = data.length > 0 && data.every((row) => selected.has(getRowId(row)))

  const toggleAll = () => {
    if (!onSelectedIdsChange) return
    onSelectedIdsChange(allSelected ? new Set() : new Set(data.map(getRowId)))
  }

  const toggleOne = (id: string | number) => {
    if (!onSelectedIdsChange) return
    const next = new Set(selected)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    onSelectedIdsChange(next)
  }

  const handleColumnDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id || !onColumnOrderChange) return
    const ids = columns.map((c) => c.id)
    const oldIndex = ids.indexOf(String(active.id))
    const newIndex = ids.indexOf(String(over.id))
    if (oldIndex === -1 || newIndex === -1) return
    onColumnOrderChange(arrayMove(ids, oldIndex, newIndex))
  }

  const headerRow = (
    <TableRow>
      {selectable && (
        <TableHead className="w-[50px] px-4">
          <Checkbox checked={allSelected} onCheckedChange={toggleAll} aria-label="Seleccionar todo" />
        </TableHead>
      )}
      {reorderable ? (
        <SortableContext items={columns.map((c) => c.id)} strategy={horizontalListSortingStrategy}>
          {columns.map((column) => (
            <HeaderCell
              key={column.id}
              column={column}
              sort={sort}
              onSortChange={onSortChange}
              reorderable={reorderable}
            />
          ))}
        </SortableContext>
      ) : (
        columns.map((column) => (
          <HeaderCell key={column.id} column={column} sort={sort} onSortChange={onSortChange} />
        ))
      )}
    </TableRow>
  )

  const body = (
    <TableBody>
      {loading ? (
        <TableRow>
          <TableCell colSpan={totalColumns} className="h-40">
            <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-5 w-5 animate-spin" />
              {loadingLabel ?? "Cargando…"}
            </div>
          </TableCell>
        </TableRow>
      ) : data.length === 0 ? (
        <TableRow>
          <TableCell colSpan={totalColumns} className="py-10 text-center text-muted-foreground">
            {emptyLabel ?? "Sin datos para mostrar"}
          </TableCell>
        </TableRow>
      ) : (
        data.map((row, idx) => {
          const id = getRowId(row)
          return (
            <TableRow
              key={id}
              data-state={selected.has(id) ? "selected" : undefined}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              className={cn(
                zebra && idx % 2 === 0 && "bg-muted/30",
                "hover:bg-muted/50 transition-colors",
                onRowClick && "cursor-pointer",
              )}
            >
              {selectable && (
                <TableCell className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={selected.has(id)}
                    onCheckedChange={() => toggleOne(id)}
                    aria-label="Seleccionar fila"
                  />
                </TableCell>
              )}
              {columns.map((column) => (
                <TableCell
                  key={column.id}
                  className={cn("py-3", column.align === "right" && "text-right", column.className)}
                  style={{ width: column.width, minWidth: column.minWidth, maxWidth: column.width }}
                >
                  {column.cell(row)}
                </TableCell>
              ))}
            </TableRow>
          )
        })
      )}
    </TableBody>
  )

  return (
    <div className={cn("border rounded-lg overflow-hidden", className)}>
      <div className="overflow-x-auto">
        {reorderable ? (
          <DndContext sensors={sensors} accessibility={dndAccessibility} collisionDetection={closestCenter} onDragEnd={handleColumnDragEnd}>
            <Table className={minTableWidth}>
              <TableHeader className={cn(stickyHeader && "sticky top-0 z-10 bg-background", "border-b")}>
                {headerRow}
              </TableHeader>
              {body}
            </Table>
          </DndContext>
        ) : (
          <Table className={minTableWidth}>
            <TableHeader className={cn(stickyHeader && "sticky top-0 z-10 bg-background", "border-b")}>
              {headerRow}
            </TableHeader>
            {body}
          </Table>
        )}
      </div>
    </div>
  )
}
