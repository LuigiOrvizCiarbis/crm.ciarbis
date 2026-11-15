"use client"

import * as React from "react"

import { cn } from "@/lib/utils"

/** Ancho mínimo del pulgar para que se pueda agarrar aunque la tabla sea muy ancha. */
const MIN_THUMB_WIDTH = 40
/** Fracción del ancho visible que avanza un clic en el riel. */
const TRACK_CLICK_PAGE = 0.9

interface ScrollMetrics {
  clientWidth: number
  scrollWidth: number
  scrollLeft: number
}

/** Medidas de scroll horizontal del contenedor; se actualiza al scrollear y al cambiar de tamaño él o su contenido. */
function useScrollMetrics(targetRef: React.RefObject<HTMLElement | null>) {
  const [metrics, setMetrics] = React.useState<ScrollMetrics | null>(null)

  React.useEffect(() => {
    const target = targetRef.current
    if (!target) return

    const update = () => {
      const next = { clientWidth: target.clientWidth, scrollWidth: target.scrollWidth, scrollLeft: target.scrollLeft }
      setMetrics((current) => (
        current?.clientWidth === next.clientWidth && current.scrollWidth === next.scrollWidth && current.scrollLeft === next.scrollLeft
          ? current
          : next
      ))
    }
    update()

    const resizeObserver = new ResizeObserver(update)
    resizeObserver.observe(target)
    for (const child of Array.from(target.children)) resizeObserver.observe(child)
    target.addEventListener("scroll", update, { passive: true })

    return () => {
      resizeObserver.disconnect()
      target.removeEventListener("scroll", update)
    }
  }, [targetRef])

  return metrics
}

/**
 * Barra de scroll horizontal propia, pegada al borde inferior de la pantalla mientras el
 * contenedor está a la vista. La nativa queda al final del contenido (hay que bajar hasta
 * el final de la tabla para encontrarla) y en macOS/iPadOS se oculta sola; esta siempre se ve.
 *
 * Sólo replica el scroll del contenedor: rueda, trackpad y teclado siguen funcionando sobre él.
 * Para que quede pegada, ningún ancestro entre ella y el contenedor que scrollea la página
 * puede tener `overflow: hidden` (usar `overflow-clip`).
 */
export function HorizontalScrollbar({
  targetRef,
  className,
}: {
  targetRef: React.RefObject<HTMLElement | null>
  className?: string
}) {
  const metrics = useScrollMetrics(targetRef)
  const trackRef = React.useRef<HTMLDivElement>(null)
  const drag = React.useRef<{ startX: number; startScrollLeft: number } | null>(null)

  if (!metrics) return null
  const maxScroll = metrics.scrollWidth - metrics.clientWidth
  if (maxScroll <= 1) return null

  const thumbFraction = Math.min(1, Math.max(metrics.clientWidth / metrics.scrollWidth, MIN_THUMB_WIDTH / metrics.clientWidth))
  const travelFraction = 1 - thumbFraction
  const thumbLeftFraction = (metrics.scrollLeft / maxScroll) * travelFraction

  const scrollToward = (clientX: number) => {
    const target = targetRef.current
    const track = trackRef.current
    if (!target || !track) return
    const rect = track.getBoundingClientRect()
    const direction = clientX < rect.left + rect.width * thumbLeftFraction ? -1 : 1
    target.scrollBy({ left: direction * target.clientWidth * TRACK_CLICK_PAGE, behavior: "smooth" })
  }

  return (
    <div
      aria-hidden="true"
      className={cn("sticky bottom-0 z-10 bg-background py-1", className)}
      onPointerDown={(event) => {
        if (event.target !== event.currentTarget && event.target !== trackRef.current) return
        event.preventDefault()
        scrollToward(event.clientX)
      }}
    >
      <div ref={trackRef} className="relative h-2 rounded-full bg-muted">
        <div
          className="absolute inset-y-0 left-0 cursor-grab touch-none rounded-full bg-muted-foreground/40 transition-colors hover:bg-muted-foreground/60 active:cursor-grabbing active:bg-muted-foreground/60"
          style={{ width: `${thumbFraction * 100}%`, left: `${thumbLeftFraction * 100}%` }}
          onPointerDown={(event) => {
            const target = targetRef.current
            if (!target) return
            event.preventDefault()
            drag.current = { startX: event.clientX, startScrollLeft: target.scrollLeft }
            event.currentTarget.setPointerCapture(event.pointerId)
          }}
          onPointerMove={(event) => {
            const target = targetRef.current
            const track = trackRef.current
            if (!drag.current || !target || !track) return
            const travelPx = track.clientWidth * travelFraction
            if (travelPx <= 0) return
            target.scrollLeft = drag.current.startScrollLeft + ((event.clientX - drag.current.startX) * maxScroll) / travelPx
          }}
          onPointerUp={(event) => {
            drag.current = null
            event.currentTarget.releasePointerCapture(event.pointerId)
          }}
          onPointerCancel={() => {
            drag.current = null
          }}
        />
      </div>
    </div>
  )
}
