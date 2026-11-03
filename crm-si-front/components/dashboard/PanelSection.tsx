import type { ReactNode } from "react"
import { cn } from "@/lib/utils"

interface PanelSectionProps {
  id: string
  title: string
  description?: string
  /** Dato o acción a la derecha del título (total, enlace). */
  aside?: ReactNode
  /** La sección protagonista lleva un título un escalón más grande. */
  prominent?: boolean
  busy?: boolean
  className?: string
  children: ReactNode
}

/**
 * Módulo del Panel: una superficie por pregunta de negocio, con encabezado propio. No se anidan:
 * adentro va el contenido directo (listas, barras), nunca otra tarjeta.
 */
export function PanelSection({ id, title, description, aside, prominent = false, busy = false, className, children }: PanelSectionProps) {
  return (
    <section
      aria-labelledby={`${id}-title`}
      aria-busy={busy || undefined}
      className={cn("min-w-0 rounded-xl border border-border bg-card p-5 text-card-foreground shadow-xs md:p-6", className)}
    >
      <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <h2 id={`${id}-title`} className={cn("font-semibold tracking-tight text-balance", prominent ? "text-lg" : "text-base")}>
            {title}
          </h2>
          {description && <p className="mt-1 max-w-[60ch] text-sm text-muted-foreground">{description}</p>}
        </div>
        {aside && <div className="shrink-0 text-sm">{aside}</div>}
      </header>
      <div className={prominent ? "mt-6" : "mt-5"}>{children}</div>
    </section>
  )
}
