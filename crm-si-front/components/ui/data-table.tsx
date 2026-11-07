"use client"

import * as React from "react"
import { ArrowDown, ArrowUp, ArrowUpDown, GripVertical } from "lucide-react"

import { cn } from "@/lib/utils"
import { Checkbox } from "@/components/ui/checkbox"
import { useDndAccessibility } from "@/hooks/useDndAccessibility"
import { useTranslation } from "@/hooks/useTranslation"
import { Skeleton } from "@/components/ui/skeleton"
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
 * La tabla de datos de toda la plataforma: contactos, tareas, catálogo, cobros, difusiones,
 * webhooks, comparativas del panel. Ninguna pantalla arma su propia tabla.
 *
 * Todo es opt-in vía props y controlado por el llamador (orden, selección, anchos, orden de
 * columnas), para que una lista simple y una planilla editable usen el mismo componente sin
 * cargar con lógica que no necesitan. Lo que no es una fila (estado de error, onboarding de
 * una sección vacía, tarjetas de teléfono) lo resuelve el llamador alrededor de la tabla.
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
  /** Nombre para lectores de pantalla cuando `header` no es texto (o está vacío, como en acciones). */
  headerLabel?: string
  /** Render de la celda para una fila. */
  cell: (row: T) => React.ReactNode
  /** Si es ordenable. Requiere `onSortChange` en la tabla. */
  sortable?: boolean
  /** Dirección con la que arranca al ordenar por esta columna por primera vez. Default "asc". */
  defaultSortDirection?: SortDirection
  /** Si puede reordenarse por drag. Requiere `reorderable` en la tabla. */
  draggable?: boolean
  /** Si se le puede cambiar el ancho. Requiere `onColumnResize` en la tabla. Default true. */
  resizable?: boolean
  /** Ancho en px ("180px") para que se pueda redimensionar; otras unidades sólo se respetan. */
  width?: string
  minWidth?: string
  maxWidth?: string
  align?: "left" | "center" | "right"
  /** La celda es el encabezado de su fila (`<th scope="row">`): nombre del ítem en comparativas. */
  rowHeader?: boolean
  /** Clase de la celda; con función, puede depender de la fila (p. ej. un número en rojo). */
  className?: string | ((row: T) => string | undefined)
  headerClassName?: string
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  data: T[]
  /** Clave estable por fila. */
  getRowId: (row: T) => string | number
  /** Nombre legible de la fila para lectores de pantalla ("Seleccionar Plan anual"). */
  getRowLabel?: (row: T) => string

  loading?: boolean
  loadingLabel?: React.ReactNode
  /** Contenido de la fila vacía. Texto corto o un bloque (ícono, título, acción). */
  emptyLabel?: React.ReactNode

  /**
   * "bordered": caja con borde (listas de una pantalla). "plain": sin caja, para tablas que ya
   * viven dentro de un panel, un diálogo o una tarjeta.
   */
  variant?: "bordered" | "plain"
  /** "compact" para tablas dentro de paneles (comparativas del panel, diálogos). */
  density?: "default" | "compact"
  /** Alto máximo con scroll vertical propio (diálogos, listas embebidas). Activa el header pegado. */
  maxHeight?: string
  /** Header pegado al hacer scroll dentro de la tabla. Default true; requiere `maxHeight` para tener efecto. */
  stickyHeader?: boolean
  /** Ancho mínimo de la tabla ("1100px") para forzar scroll horizontal en vez de apretar columnas. */
  minTableWidth?: string
  /** Respeta los anchos de columna al pie de la letra (planillas con columnas redimensionables). */
  fixedLayout?: boolean
  className?: string
  /** Contenido dentro del borde, arriba de la tabla (búsqueda y filtros propios de la tabla). */
  toolbar?: React.ReactNode
  /** Contenido dentro del borde, debajo de la tabla (paginación, barra de acciones). */
  footer?: React.ReactNode

  onRowClick?: (row: T) => void
  /** Fila "abierta" o "actual" (detalle visible, sucursal elegida). Distinta de la selección. */
  activeRowId?: string | number | null
  rowClassName?: (row: T) => string | undefined

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

  // --- Anchos de columna (controlado) ---
  /** Recibe el ancho nuevo en px, ya acotado entre minWidth y maxWidth. */
  onColumnResize?: (columnId: string, width: number) => void
}

/** Filas de esqueleto mientras carga: mantienen la forma de la tabla en vez de colapsarla a un spinner. */
const SKELETON_ROWS = 5
const RESIZE_STEP = 10
const RESIZE_STEP_LARGE = 40
/** Sin maxWidth, el autoajuste no estira una columna más que esto. */
const AUTO_FIT_CAP = 560

const toPixels = (value?: string): number | null => {
  if (!value || !value.trim().endsWith("px")) return null
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : null
}

const clampWidth = <T,>(column: DataTableColumn<T>, width: number) => {
  const min = toPixels(column.minWidth) ?? 48
  const max = toPixels(column.maxWidth) ?? Number.POSITIVE_INFINITY
  return Math.round(Math.min(max, Math.max(min, width)))
}

// "text-left" explícito: los <th> (encabezados de fila) vienen centrados por defecto en el navegador.
const alignClass = (align?: DataTableColumn<unknown>["align"]) =>
  align === "right" ? "text-right" : align === "center" ? "text-center" : "text-left"

const columnLabel = <T,>(column: DataTableColumn<T>) =>
  column.headerLabel ?? (typeof column.header === "string" ? column.header : column.id)

function SortIndicator({ active, direction }: { active: boolean; direction: SortDirection }) {
  const Icon = active ? (direction === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown
  return <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
}

/** Asa para cambiar el ancho: arrastre con captura de puntero, flechas (Shift = paso grande) y doble clic para autoajustar. */
function ResizeHandle<T>({
  column,
  onResize,
  onAutoFit,
}: {
  column: DataTableColumn<T>
  onResize: (width: number) => void
  onAutoFit: () => void
}) {
  const { t } = useTranslation()
  const drag = React.useRef<{ startX: number; startWidth: number } | null>(null)
  const width = toPixels(column.width) ?? 0
  const label = columnLabel(column)

  return (
    <button
      type="button"
      role="separator"
      aria-orientation="vertical"
      aria-label={t("dataTable.resizeColumn", { column: label })}
      aria-valuemin={toPixels(column.minWidth) ?? undefined}
      aria-valuemax={toPixels(column.maxWidth) ?? undefined}
      aria-valuenow={width}
      aria-valuetext={t("dataTable.resizeValue", { width })}
      title={t("dataTable.resizeHint")}
      className="absolute inset-y-0 right-0 z-10 w-3 cursor-col-resize touch-none rounded-sm outline-none transition-colors hover:bg-primary/40 focus-visible:bg-primary/60 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
      onPointerDown={(event) => {
        // Que no arranque el arrastre de reordenar columnas del <th>.
        event.preventDefault()
        event.stopPropagation()
        drag.current = { startX: event.clientX, startWidth: width }
        event.currentTarget.setPointerCapture(event.pointerId)
      }}
      onPointerMove={(event) => {
        if (!drag.current) return
        onResize(drag.current.startWidth + event.clientX - drag.current.startX)
      }}
      onPointerUp={(event) => {
        drag.current = null
        event.currentTarget.releasePointerCapture(event.pointerId)
      }}
      onDoubleClick={(event) => {
        event.preventDefault()
        event.stopPropagation()
        onAutoFit()
      }}
      onKeyDown={(event) => {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return
        event.preventDefault()
        event.stopPropagation()
        const step = event.shiftKey ? RESIZE_STEP_LARGE : RESIZE_STEP
        onResize(width + (event.key === "ArrowRight" ? step : -step))
      }}
    />
  )
}

function HeaderCell<T>({
  column,
  sort,
  onSortChange,
  reorderable,
  onResize,
  onAutoFit,
}: {
  column: DataTableColumn<T>
  sort?: DataTableSort | null
  onSortChange?: (sort: DataTableSort) => void
  reorderable?: boolean
  onResize?: (width: number) => void
  onAutoFit?: () => void
}) {
  const { t } = useTranslation()
  const draggable = Boolean(reorderable && column.draggable)
  const resizable = Boolean(onResize && column.resizable !== false && toPixels(column.width) !== null)
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
    maxWidth: column.width,
  }

  const isSorted = sort?.columnId === column.id
  const direction = isSorted ? sort!.direction : column.defaultSortDirection ?? "asc"

  const handleSort = () => {
    if (!column.sortable || !onSortChange) return
    onSortChange({
      columnId: column.id,
      direction: isSorted ? (direction === "asc" ? "desc" : "asc") : column.defaultSortDirection ?? "asc",
    })
  }

  // El <th> conserva su rol de encabezado: el mouse arrastra desde toda la celda y el
  // control accesible (rol, foco, teclado) es el botón del asa.
  return (
    <TableHead
      ref={setNodeRef}
      style={style}
      className={cn(
        "group/th relative",
        alignClass(column.align),
        draggable && "select-none cursor-grab active:cursor-grabbing",
        column.headerClassName,
      )}
      aria-sort={isSorted ? (direction === "asc" ? "ascending" : "descending") : undefined}
      {...(draggable ? listeners : {})}
    >
      <div
        className={cn(
          "flex min-w-0 items-center gap-2",
          column.align === "right" && "justify-end",
          column.align === "center" && "justify-center",
        )}
      >
        {draggable && (
          <button
            type="button"
            ref={setActivatorNodeRef}
            {...attributes}
            aria-label={t("dataTable.moveColumn", { column: columnLabel(column) })}
            // El asa aparece al pasar por la columna (o con foco): fija en cada encabezado es ruido.
            className="-m-1 rounded-sm p-1 text-muted-foreground opacity-0 transition-opacity hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover/th:opacity-100"
          >
            <GripVertical className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        )}
        {column.sortable && onSortChange ? (
          <button
            type="button"
            className={cn(
              "inline-flex min-w-0 items-center gap-1.5 rounded px-1 py-0.5 text-left outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50",
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
        ) : column.header === "" || column.header == null ? (
          <span className="sr-only">{columnLabel(column)}</span>
        ) : (
          <span className="truncate">{column.header}</span>
        )}
      </div>
      {resizable && onResize && onAutoFit && <ResizeHandle column={column} onResize={onResize} onAutoFit={onAutoFit} />}
    </TableHead>
  )
}

export function DataTable<T>({
  columns,
  data,
  getRowId,
  getRowLabel,
  loading = false,
  loadingLabel,
  emptyLabel,
  variant = "bordered",
  density = "default",
  maxHeight,
  stickyHeader = true,
  minTableWidth,
  fixedLayout = false,
  className,
  toolbar,
  footer,
  onRowClick,
  activeRowId,
  rowClassName,
  sort,
  onSortChange,
  selectable = false,
  selectedIds,
  onSelectedIdsChange,
  reorderable = false,
  onColumnOrderChange,
  onColumnResize,
}: DataTableProps<T>) {
  const { t } = useTranslation()
  const tableRef = React.useRef<HTMLTableElement>(null)
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const dndAccessibility = useDndAccessibility((id) => {
    const column = columns.find((item) => item.id === id)
    return column ? t("dataTable.dndColumn", { column: columnLabel(column) }) : undefined
  })

  const selected = selectedIds ?? new Set<string | number>()
  const totalColumns = columns.length + (selectable ? 1 : 0)
  const compact = density === "compact"
  const cellPadding = compact ? "px-3 py-2" : "py-2.5"

  const selectedOnPage = data.filter((row) => selected.has(getRowId(row))).length
  const allSelected = data.length > 0 && selectedOnPage === data.length
  const headerChecked = allSelected ? true : selectedOnPage > 0 ? "indeterminate" : false

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
    // Las columnas fijas (no arrastrables) no se mueven ni se dejan desplazar.
    const target = columns.find((c) => c.id === String(over.id))
    if (!target?.draggable) return
    const ids = columns.map((c) => c.id)
    const oldIndex = ids.indexOf(String(active.id))
    const newIndex = ids.indexOf(String(over.id))
    if (oldIndex === -1 || newIndex === -1) return
    onColumnOrderChange(arrayMove(ids, oldIndex, newIndex))
  }

  const resizeColumn = (column: DataTableColumn<T>, width: number) => {
    onColumnResize?.(column.id, clampWidth(column, width))
  }

  // Autoajuste: el ancho del contenido más largo de la columna en la página visible.
  const autoFitColumn = (column: DataTableColumn<T>) => {
    // window.CSS: el `CSS` importado es el de dnd-kit.
    const cells = Array.from(tableRef.current?.querySelectorAll<HTMLElement>(`[data-column-id="${window.CSS.escape(column.id)}"]`) ?? [])
    const measured = cells.reduce((width, cell) => Math.max(width, cell.scrollWidth), 0)
    if (measured === 0) return
    const cap = toPixels(column.maxWidth) ?? AUTO_FIT_CAP
    resizeColumn(column, Math.min(cap, measured + 12))
  }

  // Fila clicable también por teclado. Sólo cuando el foco está en la fila misma: Enter o
  // espacio sobre un botón o checkbox de adentro tienen que hacer lo suyo, no abrir la fila.
  const handleRowKeyDown = (event: React.KeyboardEvent<HTMLTableRowElement>, row: T) => {
    if (!onRowClick || event.target !== event.currentTarget) return
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault()
      onRowClick(row)
    }
  }

  const headerCells = columns.map((column) => (
    <HeaderCell
      key={column.id}
      column={column}
      sort={sort}
      onSortChange={onSortChange}
      reorderable={reorderable}
      onResize={onColumnResize ? (width) => resizeColumn(column, width) : undefined}
      onAutoFit={onColumnResize ? () => autoFitColumn(column) : undefined}
    />
  ))

  const headerRow = (
    <TableRow className="hover:bg-transparent">
      {selectable && (
        <TableHead className="w-[50px] px-4">
          <Checkbox
            checked={headerChecked}
            onCheckedChange={toggleAll}
            disabled={loading || data.length === 0}
            aria-label={t("dataTable.selectAll")}
          />
        </TableHead>
      )}
      {reorderable ? (
        <SortableContext items={columns.map((c) => c.id)} strategy={horizontalListSortingStrategy}>
          {headerCells}
        </SortableContext>
      ) : (
        headerCells
      )}
    </TableRow>
  )

  const cellStyle = (column: DataTableColumn<T>): React.CSSProperties => ({
    width: column.width,
    minWidth: column.minWidth,
    maxWidth: column.width,
  })

  const renderCell = (column: DataTableColumn<T>, row: T) => {
    const dynamicClass = typeof column.className === "function" ? column.className(row) : column.className
    const props = {
      "data-column-id": column.id,
      className: cn(cellPadding, alignClass(column.align), dynamicClass),
      style: cellStyle(column),
    }
    // El encabezado de fila es un <th>, pero con el peso y color de una celda: lo distingue la semántica, no el estilo.
    return column.rowHeader ? (
      <th key={column.id} scope="row" {...props} className={cn("align-middle font-medium text-foreground", !compact && "px-4", props.className)}>
        {column.cell(row)}
      </th>
    ) : (
      <TableCell key={column.id} {...props}>
        {column.cell(row)}
      </TableCell>
    )
  }

  const body = (
    <TableBody>
      {loading ? (
        Array.from({ length: SKELETON_ROWS }, (_, rowIndex) => (
          <TableRow key={`skeleton-${rowIndex}`} className="hover:bg-transparent" aria-hidden="true">
            {selectable && (
              <TableCell className="px-4 py-3">
                <Skeleton className="size-4 rounded-[4px] motion-reduce:animate-none" />
              </TableCell>
            )}
            {columns.map((column) => (
              <TableCell key={column.id} className={cn(compact ? "px-3 py-2.5" : "py-3")} style={cellStyle(column)}>
                <Skeleton
                  className={cn(
                    "h-4 rounded motion-reduce:animate-none",
                    // Anchos variados: una grilla de barras iguales se lee como patrón, no como contenido.
                    rowIndex % 3 === 0 ? "w-3/4" : rowIndex % 3 === 1 ? "w-1/2" : "w-2/3",
                    column.align === "right" && "ml-auto",
                    column.align === "center" && "mx-auto",
                  )}
                />
              </TableCell>
            ))}
          </TableRow>
        ))
      ) : data.length === 0 ? (
        <TableRow className="hover:bg-transparent">
          <TableCell colSpan={totalColumns} className="py-12 text-center text-sm text-muted-foreground">
            {emptyLabel ?? t("dataTable.empty")}
          </TableCell>
        </TableRow>
      ) : (
        data.map((row) => {
          const id = getRowId(row)
          const isSelected = selected.has(id)
          const isActive = activeRowId != null && activeRowId === id
          const rowLabel = getRowLabel?.(row)
          return (
            <TableRow
              key={id}
              data-state={isSelected ? "selected" : undefined}
              aria-current={isActive ? "true" : undefined}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              onKeyDown={onRowClick ? (event) => handleRowKeyDown(event, row) : undefined}
              tabIndex={onRowClick ? 0 : undefined}
              className={cn(
                // Seleccionada: un tinte apenas de la acción, distinto del hover neutro.
                "data-[state=selected]:bg-primary/5 data-[state=selected]:hover:bg-primary/10",
                // Activa (detalle abierto, ítem actual): superficie neutra, como el ítem activo del menú.
                isActive && "bg-muted hover:bg-muted",
                onRowClick && "cursor-pointer outline-none focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
                rowClassName?.(row),
              )}
            >
              {selectable && (
                <TableCell className={cn("px-4", compact ? "py-2" : "py-2.5")} onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={isSelected}
                    onCheckedChange={() => toggleOne(id)}
                    aria-label={rowLabel ? t("dataTable.selectRowNamed", { row: rowLabel }) : t("dataTable.selectRow")}
                  />
                </TableCell>
              )}
              {columns.map((column) => renderCell(column, row))}
            </TableRow>
          )
        })
      )}
    </TableBody>
  )

  const table = (
    <Table
      ref={tableRef}
      className={cn(fixedLayout && "table-fixed", compact && "[&_th]:h-9 [&_th]:px-3")}
      style={minTableWidth ? { minWidth: minTableWidth } : undefined}
      aria-busy={loading || undefined}
    >
      <TableHeader className={cn(stickyHeader && maxHeight && "sticky top-0 z-10 bg-background", "border-b")}>
        {headerRow}
      </TableHeader>
      {body}
    </Table>
  )

  return (
    <div className={cn(variant === "bordered" && "overflow-hidden rounded-lg border", className)}>
      {loading && (
        <span role="status" className="sr-only">
          {loadingLabel ?? t("dataTable.loading")}
        </span>
      )}
      {toolbar && <div className="border-b px-4 py-3">{toolbar}</div>}
      <div className={cn("overflow-x-auto", maxHeight && "overflow-y-auto")} style={maxHeight ? { maxHeight } : undefined}>
        {reorderable ? (
          <DndContext sensors={sensors} accessibility={dndAccessibility} collisionDetection={closestCenter} onDragEnd={handleColumnDragEnd}>
            {table}
          </DndContext>
        ) : (
          table
        )}
      </div>
      {footer && <div className="border-t px-4 py-3">{footer}</div>}
    </div>
  )
}
