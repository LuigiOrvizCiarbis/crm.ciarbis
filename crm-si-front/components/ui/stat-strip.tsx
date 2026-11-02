import type { ReactNode } from "react"
import type { LucideIcon } from "lucide-react"
import { cn } from "@/lib/utils"
import { Skeleton } from "@/components/ui/skeleton"

export interface Stat {
  label: string
  value: ReactNode
  /** Línea secundaria: comparación con el período anterior, denominador, etc. */
  detail?: ReactNode
  /** Sólo cuando el ícono comunica un estado (al día, por vencer, fallido); nunca decorativo. */
  icon?: LucideIcon
  tone?: "success" | "warning" | "destructive"
}

const toneClass = {
  success: "text-success",
  warning: "text-warning",
  destructive: "text-destructive",
} as const

// Clases literales para que Tailwind las detecte. Cada cantidad llena filas
// completas en todos los anchos, así no quedan celdas vacías al final.
const columnsClass: Record<number, string> = {
  1: "grid-cols-1",
  2: "grid-cols-2",
  3: "grid-cols-3",
  4: "grid-cols-2 lg:grid-cols-4",
  6: "grid-cols-2 sm:grid-cols-3 xl:grid-cols-6",
}

interface StatStripProps {
  stats: Stat[]
  /** Muestra las etiquetas con el valor en esqueleto, para que el layout no salte al cargar. */
  loading?: boolean
  className?: string
}

/**
 * Fila de métricas en una sola superficie con divisores finos. Reemplaza las
 * tarjetas sueltas de ícono + número gigante: el número es Headline (2xl/700)
 * y sólo los estados semánticos llevan color.
 */
export function StatStrip({ stats, loading = false, className }: StatStripProps) {
  return (
    <div className={cn("overflow-hidden rounded-xl border border-border bg-card shadow-xs", className)} aria-busy={loading || undefined}>
      {/* El -ml/-mt esconde bajo el borde exterior las líneas de la primera fila y columna. */}
      <dl className={cn("-ml-px -mt-px grid", columnsClass[stats.length] ?? "grid-cols-2 lg:grid-cols-4")}>
        {stats.map(({ label, value, detail, icon: Icon, tone }) => (
          <div key={label} className="min-w-0 border-l border-t border-border px-3 py-3 md:px-5 md:py-4">
            <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
              {Icon && <Icon className={cn("size-3.5 shrink-0", tone && toneClass[tone])} aria-hidden="true" />}
              <span className="min-w-0 leading-tight">{label}</span>
            </dt>
            <dd className="mt-1 text-xl font-bold tabular-nums md:text-2xl">
              {loading ? <Skeleton className="h-7 w-20 md:h-8" /> : value}
            </dd>
            {detail !== undefined && (
              <dd className="mt-1 text-xs text-muted-foreground">{loading ? <Skeleton className="h-3 w-28" /> : detail}</dd>
            )}
          </div>
        ))}
      </dl>
    </div>
  )
}
