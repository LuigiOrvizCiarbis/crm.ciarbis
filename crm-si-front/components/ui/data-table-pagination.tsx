"use client"

import * as React from "react"
import { ChevronLeft, ChevronRight } from "lucide-react"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"

interface DataTablePaginationProps {
  /** Página actual, base 1. */
  page: number
  pageCount: number
  onPageChange: (page: number) => void
  /** Total de filas: con él se muestra el rango "21–40 de 4.552". */
  total?: number
  pageSize?: number
  pageSizeOptions?: number[]
  onPageSizeChange?: (pageSize: number) => void
  /** Bloquea la navegación mientras carga una página. */
  disabled?: boolean
  /** Acciones de la vista de tabla junto al selector (p. ej. "Restablecer anchos"). */
  actions?: React.ReactNode
  className?: string
}

/**
 * Paginación de DataTable para toda la plataforma: el rango a la izquierda y, a la derecha,
 * filas por página y las flechas. Va arriba de la tabla, debajo o en su `footer`.
 */
export function DataTablePagination({
  page,
  pageCount,
  onPageChange,
  total,
  pageSize,
  pageSizeOptions,
  onPageSizeChange,
  disabled = false,
  actions,
  className,
}: DataTablePaginationProps) {
  const { t, language } = useTranslation()
  const perPageId = React.useId()
  // Siempre con separador de miles: el español no agrupa números de 4 cifras ("4552").
  const format = (value: number) => new Intl.NumberFormat(language, { useGrouping: "always" }).format(value)
  const pages = Math.max(1, pageCount)

  const range = (() => {
    if (total === undefined || pageSize === undefined) return null
    if (total === 0) return t("dataTable.rangeEmpty")
    const from = (page - 1) * pageSize + 1
    const to = Math.min(total, page * pageSize)
    return t("dataTable.range", { from: format(from), to: format(to), total: format(total) })
  })()

  return (
    <div className={cn("flex flex-wrap items-center justify-between gap-x-4 gap-y-2", className)}>
      {range !== null ? <p className="text-sm tabular-nums text-muted-foreground">{range}</p> : <span />}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        {actions}
        {pageSize !== undefined && pageSizeOptions && onPageSizeChange && (
          <div className="flex items-center gap-2">
            <span id={perPageId} className="text-sm text-muted-foreground">{t("dataTable.perPage")}</span>
            <Select value={String(pageSize)} onValueChange={(value) => onPageSizeChange(Number(value))}>
              <SelectTrigger className="h-8 w-20 max-md:h-10" aria-labelledby={perPageId}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {pageSizeOptions.map((option) => (
                  <SelectItem key={option} value={String(option)}>
                    {option}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="sm"
            className="size-8 p-0 max-md:size-10"
            disabled={disabled || page <= 1}
            onClick={() => onPageChange(Math.max(1, page - 1))}
            aria-label={t("dataTable.previousPage")}
            title={t("dataTable.previousPage")}
          >
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          </Button>
          <span className="min-w-16 text-center text-sm tabular-nums text-muted-foreground">
            {t("dataTable.page", { page: format(page), pages: format(pages) })}
          </span>
          <Button
            variant="ghost"
            size="sm"
            className="size-8 p-0 max-md:size-10"
            disabled={disabled || page >= pages}
            onClick={() => onPageChange(Math.min(pages, page + 1))}
            aria-label={t("dataTable.nextPage")}
            title={t("dataTable.nextPage")}
          >
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Button>
        </div>
      </div>
    </div>
  )
}
