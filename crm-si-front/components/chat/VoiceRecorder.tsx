"use client"

import { ChevronUp, Lock, Mic, Send, Trash2 } from "lucide-react"
import { useCallback, useEffect, useRef, useState } from "react"
import { useTranslation } from "@/hooks/useTranslation"
import { audioExtensionForMime, canRecordAudio, pickRecorderMimeType } from "@/lib/audio"

type RecorderState = "idle" | "recording" | "locked" | "uploading" | "error"
type Props = {
  onSend: (file: File) => void | Promise<void>
  disabled?: boolean
  canRecord?: boolean
  conversationId?: number | null
  onActiveChange?: (active: boolean) => void
}

/** Píxeles que hay que deslizar a la izquierda para cancelar la grabación. */
const CANCEL_DISTANCE = 72
/** Píxeles que hay que deslizar hacia arriba para fijar (lock) la grabación. */
const LOCK_DISTANCE = 56
/** Grabaciones más cortas que esto se descartan: fueron un toque accidental. */
const MIN_DURATION_MS = 1000
const MAX_DURATION_MS = 5 * 60 * 1000

export function VoiceRecorder({
  onSend,
  disabled = false,
  canRecord = true,
  conversationId,
  onActiveChange,
}: Props) {
  const { t } = useTranslation()
  const [state, setState] = useState<RecorderState>("idle")
  const [seconds, setSeconds] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [slidingToCancel, setSlidingToCancel] = useState(false)
  const [offsetX, setOffsetX] = useState(0)

  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const startedAtRef = useRef(0)
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const maxDurationRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  // Qué hacer cuando el recorder emita onstop: mandar el audio o tirarlo.
  const discardRef = useRef(false)
  // El gesto quedó fijado (lock): soltar el dedo ya no corta la grabación.
  const lockedRef = useRef(false)
  // getUserMedia es asíncrono. Si el usuario suelta el dedo antes de que
  // resuelva el permiso, anotamos acá el pedido y lo aplicamos apenas existe
  // el recorder; si no, el micrófono quedaría abierto sin forma de cortarlo.
  const pendingStopRef = useRef<null | "send" | "cancel">(null)
  const pointerRef = useRef<{ id: number; x: number; y: number } | null>(null)
  // Un pointerup dispara además un click sintético en mobile: lo tragamos para
  // no reabrir la grabación justo después de soltar.
  const suppressClickRef = useRef(false)
  // onSend puede cambiar de identidad en cada render del padre; leemos siempre
  // la última versión desde onstop sin recrear el recorder.
  const onSendRef = useRef(onSend)
  onSendRef.current = onSend

  const clearTimers = useCallback(() => {
    if (timerRef.current) clearInterval(timerRef.current)
    timerRef.current = null
    if (maxDurationRef.current) clearTimeout(maxDurationRef.current)
    maxDurationRef.current = null
  }, [])

  const releaseResources = useCallback(() => {
    clearTimers()
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    recorderRef.current = null
  }, [clearTimers])

  const resetGesture = useCallback(() => {
    pointerRef.current = null
    lockedRef.current = false
    setSlidingToCancel(false)
    setOffsetX(0)
  }, [])

  /** Corta la grabación descartando lo grabado. */
  const cancel = useCallback(() => {
    discardRef.current = true
    const recorder = recorderRef.current
    if (!recorder || recorder.state === "inactive") {
      pendingStopRef.current = "cancel"
      releaseResources()
      resetGesture()
      setSeconds(0)
      setState((current) => (current === "error" ? current : "idle"))
      return
    }
    clearTimers()
    recorder.stop()
  }, [clearTimers, releaseResources, resetGesture])

  /** Corta la grabación y manda el audio (si superó el mínimo de duración). */
  const finish = useCallback(() => {
    discardRef.current = false
    const recorder = recorderRef.current
    if (!recorder || recorder.state === "inactive") {
      pendingStopRef.current = "send"
      return
    }
    clearTimers()
    recorder.stop()
  }, [clearTimers])

  const start = useCallback(async () => {
    if (disabled || !canRecord) return
    if (state === "recording" || state === "locked" || state === "uploading") return
    if (recorderRef.current) return

    setError(null)
    pendingStopRef.current = null

    if (!canRecordAudio()) {
      setError(t("chats.voiceUnsupported"))
      setState("error")
      return
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })

      // Soltó el dedo mientras pedíamos permiso: no llegamos a grabar nada.
      if (pendingStopRef.current) {
        pendingStopRef.current = null
        stream.getTracks().forEach((track) => track.stop())
        resetGesture()
        setState("idle")
        return
      }

      streamRef.current = stream

      const mimeType = pickRecorderMimeType()
      const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream)
      recorderRef.current = recorder
      chunksRef.current = []
      discardRef.current = false

      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunksRef.current.push(event.data)
      }

      recorder.onstop = () => {
        const duration = Date.now() - startedAtRef.current
        const type = recorder.mimeType || mimeType || "audio/webm"
        const shouldDiscard = discardRef.current
        const chunks = chunksRef.current

        releaseResources()
        chunksRef.current = []
        resetGesture()
        setSeconds(0)

        if (shouldDiscard || chunks.length === 0) {
          setState("idle")
          return
        }

        if (duration < MIN_DURATION_MS) {
          setError(t("chats.voiceTooShort"))
          setState("idle")
          return
        }

        const extension = audioExtensionForMime(type)
        const file = new File([new Blob(chunks, { type })], `nota-de-voz-${Date.now()}.${extension}`, { type })

        setState("uploading")
        void (async () => {
          try {
            await onSendRef.current(file)
            setState("idle")
          } catch {
            setError(t("chats.voiceSendError"))
            setState("error")
          }
        })()
      }

      startedAtRef.current = Date.now()
      recorder.start()
      setSeconds(0)
      setState(lockedRef.current ? "locked" : "recording")

      timerRef.current = setInterval(() => {
        setSeconds(Math.floor((Date.now() - startedAtRef.current) / 1000))
      }, 250)
      maxDurationRef.current = setTimeout(() => {
        discardRef.current = false
        recorderRef.current?.stop()
      }, MAX_DURATION_MS)
    } catch (cause) {
      releaseResources()
      resetGesture()
      const name = cause instanceof DOMException ? cause.name : ""
      setError(
        name === "NotAllowedError" || name === "SecurityError" || name === "PermissionDeniedError"
          ? t("chats.voicePermissionError")
          : t("chats.voiceUnsupported"),
      )
      setState("error")
    }
  }, [canRecord, disabled, releaseResources, resetGesture, state, t])

  const handlePointerDown = (event: React.PointerEvent<HTMLElement>) => {
    if (disabled || !canRecord) return
    if (state === "locked" || state === "uploading") return
    // Sólo el botón principal del mouse; en touch/pen siempre es 0.
    if (event.button !== 0) return

    event.preventDefault()
    pointerRef.current = { id: event.pointerId, x: event.clientX, y: event.clientY }
    lockedRef.current = false
    setOffsetX(0)
    setSlidingToCancel(false)
    event.currentTarget.setPointerCapture(event.pointerId)
    void start()
  }

  const handlePointerMove = (event: React.PointerEvent<HTMLElement>) => {
    const pointer = pointerRef.current
    if (!pointer || pointer.id !== event.pointerId || lockedRef.current) return

    const deltaX = pointer.x - event.clientX
    const deltaY = pointer.y - event.clientY

    // El lock gana sobre el cancel: si subió lo suficiente, fija la grabación.
    if (deltaY >= LOCK_DISTANCE && deltaX < CANCEL_DISTANCE) {
      lockedRef.current = true
      setOffsetX(0)
      setSlidingToCancel(false)
      setState((current) => (current === "recording" ? "locked" : current))
      return
    }

    setOffsetX(Math.max(0, deltaX))
    setSlidingToCancel(deltaX >= CANCEL_DISTANCE)
  }

  const handlePointerUp = (event: React.PointerEvent<HTMLElement>) => {
    const pointer = pointerRef.current
    if (!pointer || pointer.id !== event.pointerId) return

    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
    suppressClickRef.current = true

    // Quedó fijada: sigue grabando y se corta con los botones de la barra.
    if (lockedRef.current) {
      pointerRef.current = null
      return
    }

    const cancelling = pointer.x - event.clientX >= CANCEL_DISTANCE
    pointerRef.current = null
    setOffsetX(0)
    setSlidingToCancel(false)
    if (cancelling) cancel()
    else finish()
  }

  const handlePointerCancel = (event: React.PointerEvent<HTMLElement>) => {
    if (pointerRef.current?.id !== event.pointerId) return
    if (lockedRef.current) {
      pointerRef.current = null
      return
    }
    pointerRef.current = null
    cancel()
  }

  useEffect(() => {
    // Cambió de conversación: lo grabado ya no corresponde a este chat.
    cancel()
  }, [conversationId, cancel])

  useEffect(() => () => { releaseResources() }, [releaseResources])

  useEffect(() => {
    onActiveChange?.(state === "recording" || state === "locked" || state === "uploading")
  }, [onActiveChange, state])

  const isRecording = state === "recording" || state === "locked"
  const timer = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`

  if (isRecording || state === "uploading") {
    return (
      <div className="flex min-w-0 flex-1 items-center gap-2">
        <span
          aria-live="polite"
          className={`flex shrink-0 items-center gap-1.5 tabular-nums text-sm ${
            slidingToCancel ? "text-destructive" : ""
          }`}
        >
          <span
            aria-hidden
            className={`h-2 w-2 rounded-full ${
              state === "uploading" ? "bg-muted-foreground" : "animate-pulse bg-destructive motion-reduce:animate-none"
            }`}
          />
          {timer}
        </span>

        {state === "locked" ? (
          <>
            <span className="flex min-w-0 flex-1 items-center gap-1 truncate text-xs text-muted-foreground">
              <Lock className="h-3 w-3 shrink-0" aria-hidden />
              {t("chats.voiceLocked")}
            </span>
            <button
              type="button"
              onClick={cancel}
              aria-label={t("chats.voiceCancel")}
              className="shrink-0 p-2 text-muted-foreground hover:text-destructive"
            >
              <Trash2 className="h-5 w-5" />
            </button>
            <button
              type="button"
              onClick={finish}
              aria-label={t("chats.voiceSend")}
              className="shrink-0 p-2 text-primary"
            >
              <Send className="h-5 w-5" />
            </button>
          </>
        ) : state === "uploading" ? (
          <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{t("chats.voiceSending")}</span>
        ) : (
          <>
            <span
              className={`min-w-0 flex-1 select-none truncate text-xs ${
                slidingToCancel ? "text-destructive" : "text-muted-foreground"
              }`}
              style={{ transform: `translateX(-${Math.min(offsetX, CANCEL_DISTANCE)}px)` }}
            >
              {slidingToCancel ? t("chats.voiceReleaseToCancel") : `‹ ${t("chats.voiceSlideToCancel")}`}
            </span>
            <span className="flex shrink-0 flex-col items-center text-muted-foreground" aria-hidden>
              <ChevronUp className="h-3 w-3 animate-bounce motion-reduce:animate-none" />
              <Lock className="h-3 w-3" />
            </span>
          </>
        )}
      </div>
    )
  }

  return (
    <div className="flex min-w-0 items-center gap-2">
      <button
        type="button"
        disabled={disabled || !canRecord}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerCancel={handlePointerCancel}
        onContextMenu={(event) => event.preventDefault()}
        onClick={() => {
          // Sólo llega acá por teclado (Enter/Espacio): sin gesto de puntero no
          // hay "soltar", así que arrancamos ya fijado y se corta con los
          // botones de enviar/descartar, que sí son alcanzables por teclado.
          if (suppressClickRef.current) {
            suppressClickRef.current = false
            return
          }
          lockedRef.current = true
          void start()
        }}
        aria-label={state === "error" ? t("chats.voiceRetry") : t("chats.voiceStart")}
        className="shrink-0 touch-none select-none p-2 transition-transform active:scale-125 active:text-destructive"
      >
        <Mic className="h-5 w-5" />
      </button>
      {error && (
        <span role="alert" className="text-xs text-destructive">
          {error}
        </span>
      )}
    </div>
  )
}
