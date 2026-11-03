import { cn } from "@/lib/utils"

interface BimsLogoProps {
  /** "full": isotipo + wordmark; "mark": sólo el isotipo (barra colapsada, avatares). */
  variant?: "full" | "mark"
  className?: string
}

/**
 * Logo provisorio de BIMS. El isotipo sale de public/brand/bims-mark.svg, la misma fuente del
 * favicon y los íconos de la PWA, así que al cambiarlo por el vectorial original se actualiza todo.
 * El wordmark es texto para que tome el color del tema en claro y en oscuro.
 */
export function BimsLogo({ variant = "full", className }: BimsLogoProps) {
  return (
    <span role="img" aria-label="BIMS" className={cn("inline-flex items-center gap-2", className)}>
      {/* eslint-disable-next-line @next/next/no-img-element -- SVG estático: next/image no lo optimiza */}
      <img src="/brand/bims-mark.svg" alt="" aria-hidden="true" width={32} height={32} className="size-8 shrink-0" />
      {variant === "full" && (
        <span className="text-lg font-extrabold leading-none tracking-[0.14em] text-foreground" aria-hidden="true">
          BIMS
        </span>
      )}
    </span>
  )
}
