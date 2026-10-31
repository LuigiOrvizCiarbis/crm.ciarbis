"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { useIsMobile } from "@/hooks/use-mobile"
import { useAudioRecorder, type AudioRecorderError } from "@/hooks/use-audio-recorder"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { Mic, Square, Trash2 } from "lucide-react"

/** Deslizamiento horizontal, en px, que cancela la grabación en mobile (estilo WhatsApp). */
const SLIDE_TO_CANCEL_THRESHOLD = 80

interface AudioRecorderProps {
  disabled?: boolean
  onRecorded: (file: File) => void
  onError?: (error: AudioRecorderError) => void
  /** Avisa al padre si está grabando, para que no desmonte este botón (p.ej. si el textarea deja de estar vacío) ni lo tape con otra UI mientras graba. */
  onRecordingChange?: (isRecording: boolean) => void
}

export function AudioRecorder({ disabled, onRecorded, onError, onRecordingChange }: AudioRecorderProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [slideOffset, setSlideOffset] = useState(0)
  const pointerStartXRef = useRef<number | null>(null)
  const cancelledBySlideRef = useRef(false)

  const { status, elapsedSeconds, start, stop, cancel } = useAudioRecorder({ onRecorded, onError })
  const isRecording = status === "recording"

  useEffect(() => {
    onRecordingChange?.(isRecording)
  }, [isRecording, onRecordingChange])

  const resetSlide = useCallback(() => {
    pointerStartXRef.current = null
    cancelledBySlideRef.current = false
    setSlideOffset(0)
  }, [])

  // Desktop (o cualquier puntero tipo mouse, para híbridos táctil-con-mouse):
  // click para empezar, click de nuevo para parar. No usa hold.
  const handleClickToggle = () => {
    if (disabled) return
    if (isRecording) {
      stop()
    } else {
      void start()
    }
  }

  // Mobile: mantener presionado. setPointerCapture asegura que el evento de
  // fin llegue a este mismo botón aunque el dedo se deslice fuera de sus
  // bounds. Crítico: este botón es SIEMPRE el mismo nodo DOM (nunca se
  // desmonta al pasar a "recording", sólo cambia su contenido interno) —
  // si el nodo cambiara, la captura del puntero se perdería a mitad de gesto
  // y el swipe-to-cancel/soltar dejarían de funcionar.
  const handlePointerDown = (e: React.PointerEvent<HTMLButtonElement>) => {
    if (disabled || e.pointerType === "mouse" || isRecording) return
    e.preventDefault()
    e.currentTarget.setPointerCapture(e.pointerId)
    pointerStartXRef.current = e.clientX
    cancelledBySlideRef.current = false
    void start()
  }

  const handlePointerMove = (e: React.PointerEvent<HTMLButtonElement>) => {
    if (pointerStartXRef.current === null || !isRecording) return
    const delta = pointerStartXRef.current - e.clientX
    const clamped = Math.max(0, Math.min(delta, SLIDE_TO_CANCEL_THRESHOLD * 1.5))
    setSlideOffset(clamped)

    if (delta > SLIDE_TO_CANCEL_THRESHOLD && !cancelledBySlideRef.current) {
      cancelledBySlideRef.current = true
      cancel()
    }
  }

  const handlePointerUp = (e: React.PointerEvent<HTMLButtonElement>) => {
    if (e.pointerType === "mouse" || pointerStartXRef.current === null) return
    if (!cancelledBySlideRef.current) {
      stop()
    }
    resetSlide()
  }

  // Notificación entrante, llamada, o el sistema interrumpe el gesto: el
  // pointerup nunca llega. Conservamos lo grabado en vez de descartarlo.
  const handlePointerCancel = (e: React.PointerEvent<HTMLButtonElement>) => {
    if (e.pointerType === "mouse" || pointerStartXRef.current === null) return
    if (!cancelledBySlideRef.current) {
      stop()
    }
    resetSlide()
  }

  const handleKeyDown = (e: React.KeyboardEvent<HTMLButtonElement>) => {
    if (e.key === "Escape" && isRecording) {
      e.preventDefault()
      cancel()
    }
  }

  const minutes = Math.floor(elapsedSeconds / 60)
  const seconds = elapsedSeconds % 60
  const timeLabel = `${minutes}:${seconds.toString().padStart(2, "0")}`
  const isSlidingToCancel = slideOffset > 0

  // Mobile: un único botón persistente (idle -> hold -> stop), con el gesto
  // pointer siempre atado al mismo nodo. El tiempo y "deslizá para cancelar"
  // se muestran al lado mientras graba.
  if (isMobile) {
    return (
      <div className="flex items-center gap-2">
        {isRecording && (
          <>
            <span
              className="hidden items-center gap-1 text-xs text-muted-foreground transition-opacity sm:flex"
              style={{ opacity: Math.max(0.3, 1 - slideOffset / SLIDE_TO_CANCEL_THRESHOLD) }}
            >
              {t("chats.slideToCancel")}
            </span>
            <span className="flex items-center gap-1.5 text-sm text-destructive tabular-nums">
              <span className="h-2 w-2 rounded-full bg-destructive animate-pulse motion-reduce:animate-none" />
              {timeLabel}
            </span>
          </>
        )}
        <Button
          type="button"
          variant={isRecording ? "destructive" : "ghost"}
          size="sm"
          disabled={disabled}
          aria-label={isRecording ? t("chats.stopRecording") : t("chats.recordAudio")}
          className={cn(isRecording && isSlidingToCancel && "opacity-60")}
          style={{
            transform: isRecording ? `translateX(${-slideOffset}px)` : undefined,
            touchAction: "none",
            userSelect: "none",
          }}
          onPointerDown={handlePointerDown}
          onPointerMove={handlePointerMove}
          onPointerUp={handlePointerUp}
          onPointerCancel={handlePointerCancel}
          onKeyDown={handleKeyDown}
        >
          {isRecording ? <Square className="w-4 h-4" /> : <Mic className="w-4 h-4" />}
        </Button>
      </div>
    )
  }

  // Desktop: click-toggle. Acá sí puede cambiar de botón entre estados
  // porque no hay pointer capture activo (sin gesto de hold que preservar).
  return (
    <div className="flex items-center gap-2">
      {isRecording && (
        <>
          <span className="flex items-center gap-1.5 text-sm text-destructive tabular-nums">
            <span className="h-2 w-2 rounded-full bg-destructive animate-pulse motion-reduce:animate-none" />
            {timeLabel}
          </span>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-label={t("chats.discardRecording")}
            onClick={() => cancel()}
          >
            <Trash2 className="w-4 h-4" />
          </Button>
        </>
      )}
      <Button
        type="button"
        variant={isRecording ? "destructive" : "ghost"}
        size="sm"
        disabled={disabled}
        aria-label={isRecording ? t("chats.stopRecording") : t("chats.recordAudio")}
        onClick={handleClickToggle}
        onKeyDown={handleKeyDown}
      >
        {isRecording ? <Square className="w-4 h-4" /> : <Mic className="w-4 h-4" />}
      </Button>
    </div>
  )
}
