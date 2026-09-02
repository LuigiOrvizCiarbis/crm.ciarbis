"use client"

import type { LucideIcon } from "lucide-react"
import type { ReactNode } from "react"

import { cn } from "@/lib/utils"

interface SettingsBlockProps {
  title: string
  description?: string
  icon?: LucideIcon
  /** Acción principal del bloque, alineada a la derecha del encabezado. */
  action?: ReactNode
  /**
   * Ancho de la columna de contenido. `prose` para formularios y listas de
   * lectura, `full` para tablas y tableros que necesitan todo el ancho.
   */
  measure?: "prose" | "full"
  className?: string
  children: ReactNode
}

/**
 * Unidad de contenido de /configuracion. Reemplaza a `Card` en los bloques que
 * ya viven dentro de una sección con encabezado: la caja duplicaba jerarquía y
 * anidaba contenedores. La separación se resuelve con espacio y un divisor.
 */
export function SettingsBlock({
  title,
  description,
  icon: Icon,
  action,
  measure = "full",
  className,
  children,
}: SettingsBlockProps) {
  return (
    <section className={cn("min-w-0", className)}>
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div className="min-w-0 space-y-1">
          <h3 className="flex items-center gap-2 text-[0.9375rem] font-semibold text-foreground">
            {Icon && (
              <Icon
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden
              />
            )}
            {title}
          </h3>
          {description && (
            <p className="max-w-[68ch] text-sm leading-6 text-muted-foreground">
              {description}
            </p>
          )}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>

      <div className={cn("mt-5", measure === "prose" && "max-w-2xl")}>
        {children}
      </div>
    </section>
  )
}
