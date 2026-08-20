"use client"

import { Mic, Send, Square, X } from "lucide-react"
import { useCallback, useEffect, useRef, useState } from "react"
import { useTranslation } from "@/hooks/useTranslation"
import { pauseOtherAudios } from "@/lib/audio"

type RecorderState = "idle" | "recording" | "preview" | "uploading" | "error"
type Props = { onSend: (file: File) => void | Promise<void>; disabled?: boolean; canRecord?: boolean; conversationId?: number | null }
const CANCEL_DISTANCE = 72

export function VoiceRecorder({
  onSend,
  disabled = false,
  canRecord = true,
  conversationId,
  onActiveChange,
}: Props & { onActiveChange?: (active: boolean) => void }) {
  const { t } = useTranslation()
  const [state, setState] = useState<RecorderState>("idle")
  const [file, setFile] = useState<File | null>(null)
  const [url, setUrl] = useState<string | null>(null)
  const [seconds, setSeconds] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [slidingToCancel, setSlidingToCancel] = useState(false)
  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const cancelledRef = useRef(false)
  const touchHeldRef = useRef(false)
  const touchInitiatedRef = useRef(false)
  const pointerRef = useRef<{ id: number; x: number } | null>(null)
  const suppressClickRef = useRef(false)

  const releaseResources = useCallback(() => {
    if (timerRef.current) clearInterval(timerRef.current)
    timerRef.current = null
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    recorderRef.current = null
  }, [])

  const resetPreview = useCallback(() => {
    setFile(null)
    setUrl((old) => { if (old) URL.revokeObjectURL(old); return null })
    setSeconds(0); setSlidingToCancel(false); setError(null); setState("idle")
  }, [])

  const cancel = useCallback(() => {
    cancelledRef.current = true
    touchHeldRef.current = false
    touchInitiatedRef.current = false
    if (recorderRef.current?.state === "recording") recorderRef.current.stop()
    releaseResources(); resetPreview()
  }, [releaseResources, resetPreview])

  const stop = useCallback(() => {
    if (timerRef.current) clearInterval(timerRef.current)
    timerRef.current = null
    if (recorderRef.current?.state === "recording") recorderRef.current.stop()
  }, [])

  const start = useCallback(async () => {
    if (disabled || !canRecord || state !== "idle") return
    setError(null)
    if (typeof navigator === "undefined" || !navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
      setError(t("chats.voiceUnsupported")); setState("error"); return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const startedFromTouch = touchInitiatedRef.current
      touchInitiatedRef.current = false
      if (startedFromTouch && !touchHeldRef.current) {
        stream.getTracks().forEach((track) => track.stop())
        return
      }
      streamRef.current = stream
      const mimeType = ["audio/ogg;codecs=opus", "audio/webm;codecs=opus", "audio/mp4", "audio/webm"].find((type) => MediaRecorder.isTypeSupported(type))
      const recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined)
      cancelledRef.current = false; chunksRef.current = []
      recorder.ondataavailable = (event) => { if (event.data.size) chunksRef.current.push(event.data) }
      recorder.onstop = () => {
        const wasCancelled = cancelledRef.current
        const type = recorder.mimeType || "audio/webm"
        releaseResources()
        if (wasCancelled) return
        const extension = type.includes("mp4") ? "m4a" : type.includes("ogg") ? "ogg" : "webm"
        const nextFile = new File([new Blob(chunksRef.current, { type })], `voice.${extension}`, { type })
        setFile(nextFile)
        setUrl((old) => { if (old) URL.revokeObjectURL(old); return URL.createObjectURL(nextFile) })
        setState("preview")
      }
      recorderRef.current = recorder; recorder.start(); setSeconds(0); setState("recording")
      timerRef.current = setInterval(() => setSeconds((value) => { if (value >= 299) { stop(); return 300 }; return value + 1 }), 1000)
    } catch (cause) {
      touchHeldRef.current = false
      touchInitiatedRef.current = false
      releaseResources()
      setError(cause instanceof DOMException && (cause.name === "NotAllowedError" || cause.name === "SecurityError") ? t("chats.voicePermissionError") : t("chats.voiceUnsupported"))
      setState("error")
    }
  }, [canRecord, disabled, releaseResources, state, stop, t])

  const send = async () => {
    if (!file || state === "uploading") return
    setState("uploading")
    try { await onSend(file); resetPreview() } catch { setError(t("chats.voiceSendError")); setState("preview") }
  }

  const handlePointerDown = (event: React.PointerEvent<HTMLElement>) => {
    if (event.pointerType === "touch") {
      touchHeldRef.current = true
      touchInitiatedRef.current = true
      pointerRef.current = { id: event.pointerId, x: event.clientX }
      event.currentTarget.setPointerCapture(event.pointerId); void start()
    }
  }
  const handlePointerMove = (event: React.PointerEvent<HTMLElement>) => {
    const pointer = pointerRef.current
    if (pointer && pointer.id === event.pointerId) setSlidingToCancel(pointer.x - event.clientX >= CANCEL_DISTANCE)
  }
  const handlePointerUp = (event: React.PointerEvent<HTMLElement>) => {
    if (pointerRef.current?.id !== event.pointerId) return
    const cancelling = pointerRef.current.x - event.clientX >= CANCEL_DISTANCE
    touchHeldRef.current = false
    pointerRef.current = null; suppressClickRef.current = true
    if (cancelling) cancel(); else stop()
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId)
  }

  useEffect(() => () => { cancel(); releaseResources() }, [cancel, releaseResources])
  useEffect(() => { cancel() }, [conversationId, cancel])
  useEffect(() => {
    onActiveChange?.(state === "recording" || state === "preview" || state === "uploading")
  }, [onActiveChange, state])

  const isActive = state === "recording" || state === "preview" || state === "uploading"

  return (
    <div
      className={`flex min-w-0 items-center gap-2 ${isActive ? "flex-1 justify-between" : ""}`}
      onPointerDown={handlePointerDown}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      onPointerCancel={() => {
        touchHeldRef.current = false
        pointerRef.current = null
        cancel()
      }}
    >
      {(state === "idle" || state === "error") && (
        <>
          <button
            type="button"
            disabled={disabled || !canRecord}
            onClick={() => {
              if (suppressClickRef.current) {
                suppressClickRef.current = false
                return
              }
              void start()
            }}
            aria-label={state === "error" ? t("chats.voiceRetry") : t("chats.voiceStart")}
            className="p-2"
          >
            <Mic className="h-5 w-5" />
          </button>
          {error && <span role="alert" className="text-xs text-destructive">{error}</span>}
        </>
      )}

      {state === "recording" && (
        <>
          <span aria-live="polite">
            ● {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, "0")}
          </span>
          <span className="text-xs">
            {slidingToCancel ? t("chats.voiceReleaseToCancel") : t("chats.voiceSlideToCancel")}
          </span>
          <button type="button" onClick={stop} aria-label={t("chats.voiceStop")}>
            <Square className="h-5 w-5" />
          </button>
          <button type="button" onClick={cancel} aria-label={t("chats.voiceCancel")}>
            <X className="h-5 w-5" />
          </button>
        </>
      )}

      {(state === "preview" || state === "uploading") && (
        <>
          <audio className="min-w-0 flex-1" controls src={url ?? undefined} onPlay={pauseOtherAudios} />
          <button
            type="button"
            disabled={state === "uploading"}
            onClick={send}
            aria-label={t("chats.voiceSend")}
          >
            <Send className="h-5 w-5" />
          </button>
          <button type="button" disabled={state === "uploading"} onClick={cancel} aria-label={t("chats.voiceCancel")}>
            <X className="h-5 w-5" />
          </button>
          {error && <span role="alert" className="text-xs text-destructive">{error}</span>}
        </>
      )}
    </div>
  )
}
