import { useCallback, useEffect, useRef } from "react"

interface UseLongPressOptions {
  /** Milisegundos que hay que sostener para disparar. */
  delay?: number
  /** Píxeles de desplazamiento que cancelan el gesto (scroll). */
  moveTolerance?: number
}

/**
 * Toque sostenido para touch. Devuelve handlers para pegar al elemento.
 *
 * Dos cuidados que hacen la diferencia entre un long-press usable y uno molesto:
 * se cancela apenas el dedo se desplaza (si no, se dispara al scrollear), y
 * suprime el menú contextual nativo mientras el gesto está activo.
 */
export function useLongPress(
  onLongPress: () => void,
  { delay = 500, moveTolerance = 10 }: UseLongPressOptions = {},
) {
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const startRef = useRef<{ x: number; y: number } | null>(null)
  const firedRef = useRef(false)

  const clear = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    startRef.current = null
  }, [])

  useEffect(() => clear, [clear])

  const onTouchStart = useCallback(
    (event: React.TouchEvent) => {
      const touch = event.touches[0]
      if (!touch) return

      firedRef.current = false
      startRef.current = { x: touch.clientX, y: touch.clientY }

      timerRef.current = setTimeout(() => {
        firedRef.current = true
        startRef.current = null
        onLongPress()
      }, delay)
    },
    [delay, onLongPress],
  )

  const onTouchMove = useCallback(
    (event: React.TouchEvent) => {
      const start = startRef.current
      const touch = event.touches[0]
      if (!start || !touch) return

      const moved =
        Math.abs(touch.clientX - start.x) > moveTolerance ||
        Math.abs(touch.clientY - start.y) > moveTolerance

      if (moved) clear()
    },
    [clear, moveTolerance],
  )

  const onTouchEnd = useCallback(() => clear(), [clear])

  // Sin esto Android abre su propio menú de selección encima de la hoja.
  const onContextMenu = useCallback((event: React.MouseEvent) => {
    if (firedRef.current) event.preventDefault()
  }, [])

  return { onTouchStart, onTouchMove, onTouchEnd, onTouchCancel: onTouchEnd, onContextMenu }
}
