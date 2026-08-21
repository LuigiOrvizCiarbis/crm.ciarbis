import { useCallback, useEffect, useRef, useState } from "react"
import { pickRecorderMimeType, audioExtensionForMime } from "@/lib/audio"

const MAX_DURATION_MS = 5 * 60 * 1000
const MIN_DURATION_MS = 1000

export type AudioRecorderStatus = "idle" | "recording" | "processing"
export type AudioRecorderError = "not-allowed" | "unavailable" | "unknown"

interface UseAudioRecorderOptions {
  /** Se llama con el audio grabado, sólo si dura >= 1s y no fue cancelado. */
  onRecorded: (file: File) => void
  onError?: (error: AudioRecorderError) => void
}

interface UseAudioRecorderResult {
  status: AudioRecorderStatus
  /** Segundos transcurridos desde que empezó a grabar. */
  elapsedSeconds: number
  start: () => Promise<void>
  /** Detiene y conserva lo grabado (si pasa el mínimo de 1s). */
  stop: () => void
  /** Corta y descarta lo grabado, sin llamar a onRecorded. */
  cancel: () => void
}

/**
 * Graba audio con MediaRecorder y libera el micrófono al terminar (stop,
 * cancel o unmount) para no dejar el indicador de grabación prendido en mobile.
 */
export function useAudioRecorder({ onRecorded, onError }: UseAudioRecorderOptions): UseAudioRecorderResult {
  const [status, setStatus] = useState<AudioRecorderStatus>("idle")
  const [elapsedSeconds, setElapsedSeconds] = useState(0)

  const mediaRecorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const startedAtRef = useRef<number>(0)
  const discardRef = useRef(false)
  // getUserMedia es asíncrono: si el usuario suelta el dedo (o cancela) antes
  // de que resuelva, el stop/cancel llegaría cuando todavía no hay recorder y
  // se perdería, dejando el micrófono abierto sin forma de cortarlo desde la
  // UI. Anotamos el pedido acá y lo aplicamos apenas el recorder existe.
  const pendingStopRef = useRef<null | "stop" | "cancel">(null)
  const tickIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const maxDurationTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const releaseStream = useCallback(() => {
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null

    if (tickIntervalRef.current) {
      clearInterval(tickIntervalRef.current)
      tickIntervalRef.current = null
    }
    if (maxDurationTimeoutRef.current) {
      clearTimeout(maxDurationTimeoutRef.current)
      maxDurationTimeoutRef.current = null
    }
  }, [])

  useEffect(() => releaseStream, [releaseStream])

  const start = useCallback(async () => {
    if (mediaRecorderRef.current) return
    pendingStopRef.current = null

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })

      // El usuario soltó/canceló mientras esperábamos el permiso: no llegamos
      // a grabar nada, así que liberamos el micrófono y salimos.
      if (pendingStopRef.current) {
        pendingStopRef.current = null
        stream.getTracks().forEach((track) => track.stop())
        setStatus("idle")
        return
      }

      streamRef.current = stream

      const mimeType = pickRecorderMimeType()
      const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream)
      mediaRecorderRef.current = recorder
      chunksRef.current = []
      discardRef.current = false

      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunksRef.current.push(event.data)
      }

      recorder.onstop = () => {
        const duration = Date.now() - startedAtRef.current
        const finalMimeType = recorder.mimeType || mimeType || "audio/webm"
        releaseStream()
        mediaRecorderRef.current = null

        const shouldDiscard = discardRef.current || duration < MIN_DURATION_MS
        if (!shouldDiscard && chunksRef.current.length > 0) {
          const blob = new Blob(chunksRef.current, { type: finalMimeType })
          const extension = audioExtensionForMime(finalMimeType)
          const file = new File([blob], `nota-de-voz-${Date.now()}.${extension}`, { type: finalMimeType })
          onRecorded(file)
        }

        chunksRef.current = []
        setStatus("idle")
        setElapsedSeconds(0)
      }

      startedAtRef.current = Date.now()
      recorder.start()
      setStatus("recording")
      setElapsedSeconds(0)

      tickIntervalRef.current = setInterval(() => {
        setElapsedSeconds(Math.floor((Date.now() - startedAtRef.current) / 1000))
      }, 250)

      maxDurationTimeoutRef.current = setTimeout(() => {
        mediaRecorderRef.current?.stop()
      }, MAX_DURATION_MS)
    } catch (error) {
      releaseStream()
      mediaRecorderRef.current = null
      setStatus("idle")

      const domError = error as DOMException
      if (domError?.name === "NotAllowedError" || domError?.name === "PermissionDeniedError") {
        onError?.("not-allowed")
      } else if (domError?.name === "NotFoundError") {
        onError?.("unavailable")
      } else {
        onError?.("unknown")
      }
    }
  }, [onRecorded, onError, releaseStream])

  const stop = useCallback(() => {
    const recorder = mediaRecorderRef.current
    if (!recorder || recorder.state === "inactive") {
      // Todavía estamos esperando getUserMedia: dejamos anotado el pedido.
      pendingStopRef.current = "stop"
      return
    }
    setStatus("processing")
    discardRef.current = false
    recorder.stop()
  }, [])

  const cancel = useCallback(() => {
    const recorder = mediaRecorderRef.current
    if (!recorder || recorder.state === "inactive") {
      pendingStopRef.current = "cancel"
      return
    }
    discardRef.current = true
    recorder.stop()
  }, [])

  return { status, elapsedSeconds, start, stop, cancel }
}
