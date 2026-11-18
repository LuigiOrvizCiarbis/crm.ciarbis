import { useCallback, useRef, useState } from "react"

/** Píxeles que hay que arrastrar a la derecha para disparar la respuesta. */
export const SWIPE_REPLY_THRESHOLD = 64
const MAX_OFFSET = 80
const AXIS_LOCK_PX = 10
/** Controles que ya usan el arrastre horizontal (barra de audio/video, inputs). */
const NON_SWIPE_TARGETS = '[role="slider"], input, video, audio'

const noop = () => {}

export interface SwipeToReplyHandlers {
  offset: number
  dragging: boolean
  onTouchStart: (event: React.TouchEvent) => void
  onTouchMove: (event: React.TouchEvent) => void
  onTouchEnd: () => void
  onTouchCancel: () => void
}

/**
 * Deslizar a la derecha para responder (como WhatsApp en mobile).
 *
 * El eje se bloquea recién después de unos píxeles: si el gesto arranca
 * vertical (o hacia la izquierda) se ignora entero y el scroll queda nativo.
 * Solo dispara al soltar pasado el umbral; cancelar nunca responde.
 */
export function useSwipeToReply(
  onReply: () => void,
  { enabled = true }: { enabled?: boolean } = {},
): SwipeToReplyHandlers {
  const [offset, setOffset] = useState(0)
  const [dragging, setDragging] = useState(false)
  const startRef = useRef<{ x: number; y: number } | null>(null)
  const axisRef = useRef<"x" | "y" | null>(null)
  const offsetRef = useRef(0)

  const reset = useCallback(() => {
    startRef.current = null
    axisRef.current = null
    offsetRef.current = 0
    setOffset(0)
    setDragging(false)
  }, [])

  const onTouchStart = useCallback(
    (event: React.TouchEvent) => {
      const touch = event.touches[0]
      if (!enabled || !touch) return
      if (event.target instanceof Element && event.target.closest(NON_SWIPE_TARGETS)) return
      startRef.current = { x: touch.clientX, y: touch.clientY }
      axisRef.current = null
      setDragging(true)
    },
    [enabled],
  )

  const onTouchMove = useCallback((event: React.TouchEvent) => {
    const start = startRef.current
    const touch = event.touches[0]
    if (!start || !touch) return

    const dx = touch.clientX - start.x
    const dy = touch.clientY - start.y

    if (axisRef.current === null) {
      if (Math.abs(dx) <= AXIS_LOCK_PX && Math.abs(dy) <= AXIS_LOCK_PX) return
      axisRef.current = Math.abs(dx) > Math.abs(dy) && dx > 0 ? "x" : "y"
    }
    if (axisRef.current !== "x") return

    const next = Math.min(Math.max(dx, 0), MAX_OFFSET)
    offsetRef.current = next
    setOffset(next)
  }, [])

  const onTouchEnd = useCallback(() => {
    if (axisRef.current === "x" && offsetRef.current >= SWIPE_REPLY_THRESHOLD) onReply()
    reset()
  }, [onReply, reset])

  if (!enabled) {
    return { offset: 0, dragging: false, onTouchStart: noop, onTouchMove: noop, onTouchEnd: noop, onTouchCancel: noop }
  }
  return { offset, dragging, onTouchStart, onTouchMove, onTouchEnd, onTouchCancel: reset }
}
