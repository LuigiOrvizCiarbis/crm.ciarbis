"use client"

import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent, type ReactNode, type SyntheticEvent } from "react"
import { AlertCircle, Loader2, Pause, Play } from "lucide-react"
import { cn } from "@/lib/utils"
import { useTranslation } from "@/hooks/useTranslation"
import { pauseOtherAudios } from "@/lib/audio"

const RATES = [1, 1.5, 2] as const
const SEEK_STEP_SECONDS = 5

/** Nombres que arma el sistema (wa_6ab6bc…, nota-de-voz-1730…): a quien escucha no le dicen nada. */
const GENERATED_NAME = /^(wa_[0-9a-f]+|nota-de-voz-\d+|audio[-_]?\d*)\.[a-z0-9]+$/i

function formatTime(seconds: number | null): string {
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return "-:--"
  const total = Math.floor(seconds)
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`
}

interface AudioPlayerProps {
  src: string
  filename?: string | null
  /** Sobre la burbuja saliente (fondo de acción): invierte botón y barra. */
  onAccent?: boolean
  /** Hora y estado de la burbuja: comparten la fila del tiempo, como en WhatsApp, en vez de sumar otra línea. */
  meta?: ReactNode
  className?: string
}

/**
 * Reproductor de audios y notas de voz del chat. Reemplaza al control nativo, que en cada
 * navegador se ve distinto y no toma el color de la burbuja. Usa un <audio> sin controles por
 * debajo, así `pauseOtherAudios` sigue cortando cualquier otro audio de la página al dar play.
 */
export function AudioPlayer({ src, filename, onAccent = false, meta, className }: AudioPlayerProps) {
  const { t, language } = useTranslation()
  const audioRef = useRef<HTMLAudioElement>(null)
  const trackRef = useRef<HTMLDivElement>(null)
  const fixingDurationRef = useRef(false)
  const [playing, setPlaying] = useState(false)
  const [buffering, setBuffering] = useState(false)
  const [failed, setFailed] = useState(false)
  const [duration, setDuration] = useState<number | null>(null)
  const [current, setCurrent] = useState(0)
  const [rate, setRate] = useState<(typeof RATES)[number]>(1)
  const [dragRatio, setDragRatio] = useState<number | null>(null)

  const name = filename && !GENERATED_NAME.test(filename.trim()) ? filename.trim() : null
  const seekable = duration !== null && duration > 0
  const started = playing || current > 0
  const ratio = dragRatio ?? (seekable ? Math.min(1, current / duration) : 0)
  const shownCurrent = dragRatio !== null && seekable ? dragRatio * duration : current
  const formatRate = (value: number) => `${new Intl.NumberFormat(language, { maximumFractionDigits: 1 }).format(value)}×`

  // timeupdate llega 4 veces por segundo: la barra saltaría. Mientras suena se lee el tiempo en cada cuadro.
  useEffect(() => {
    if (!playing) return
    let frame = 0
    const tick = () => {
      const audio = audioRef.current
      if (audio && !fixingDurationRef.current) setCurrent(audio.currentTime)
      frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
  }, [playing])

  const readDuration = (audio: HTMLAudioElement) => {
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
      setDuration(audio.duration)
      if (fixingDurationRef.current) {
        fixingDurationRef.current = false
        audio.currentTime = 0
      }
      return
    }
    // Las grabaciones webm de MediaRecorder no traen duración (Infinity) hasta leerse enteras:
    // saltar al final la obliga a calcularse, y después se vuelve al inicio.
    if (audio.duration === Infinity && !fixingDurationRef.current) {
      fixingDurationRef.current = true
      audio.currentTime = 1e101
    }
  }

  const togglePlay = () => {
    const audio = audioRef.current
    if (!audio) return
    if (!audio.paused) {
      audio.pause()
      return
    }
    setBuffering(true)
    audio.play().catch((error: unknown) => {
      setBuffering(false)
      // AbortError: otro play/pause interrumpió este; no es un error del archivo.
      if (!(error instanceof DOMException && error.name === "AbortError")) setFailed(true)
    })
  }

  const seekTo = (seconds: number) => {
    const audio = audioRef.current
    if (!audio || !seekable) return
    const next = Math.min(duration, Math.max(0, seconds))
    audio.currentTime = next
    setCurrent(next)
  }

  const ratioAt = (clientX: number) => {
    const rect = trackRef.current?.getBoundingClientRect()
    if (!rect || rect.width === 0) return 0
    return Math.min(1, Math.max(0, (clientX - rect.left) / rect.width))
  }

  const handlePointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (!seekable) return
    event.currentTarget.setPointerCapture(event.pointerId)
    setDragRatio(ratioAt(event.clientX))
  }

  const handlePointerMove = (event: PointerEvent<HTMLDivElement>) => {
    if (dragRatio === null) return
    setDragRatio(ratioAt(event.clientX))
  }

  const handlePointerUp = (event: PointerEvent<HTMLDivElement>) => {
    if (dragRatio === null) return
    event.currentTarget.releasePointerCapture(event.pointerId)
    if (seekable) seekTo(dragRatio * duration)
    setDragRatio(null)
  }

  const handleTrackKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (!seekable) return
    const actions: Record<string, () => void> = {
      ArrowLeft: () => seekTo(current - SEEK_STEP_SECONDS),
      ArrowDown: () => seekTo(current - SEEK_STEP_SECONDS),
      ArrowRight: () => seekTo(current + SEEK_STEP_SECONDS),
      ArrowUp: () => seekTo(current + SEEK_STEP_SECONDS),
      Home: () => seekTo(0),
      End: () => seekTo(duration),
    }
    const action = actions[event.key]
    if (!action) return
    event.preventDefault()
    action()
  }

  const cycleRate = () => {
    const next = RATES[(RATES.indexOf(rate) + 1) % RATES.length]
    setRate(next)
    if (audioRef.current) audioRef.current.playbackRate = next
  }

  const handleEnded = () => {
    setPlaying(false)
    setCurrent(0)
    if (audioRef.current) audioRef.current.currentTime = 0
  }

  const handlePlay = (event: SyntheticEvent<HTMLAudioElement>) => {
    pauseOtherAudios(event)
    setPlaying(true)
  }

  const metaRow = (left: ReactNode) => (
    <div className={cn("mt-0.5 flex h-5 items-center justify-between gap-2 text-[11px] tabular-nums", !onAccent && "text-muted-foreground")}>
      <span>{left}</span>
      {meta && <span className="flex items-center gap-1">{meta}</span>}
    </div>
  )

  if (failed) {
    return (
      <div className={cn("w-64 max-w-full", className)}>
        <div className="flex items-center gap-2 py-1 text-sm" role="alert">
          <AlertCircle className="size-4 shrink-0" aria-hidden="true" />
          <span className="min-w-0 flex-1">{t("chats.audio.loadError")}</span>
          <a
            href={src}
            target="_blank"
            rel="noopener noreferrer"
            className="shrink-0 rounded-sm font-medium underline underline-offset-2 outline-none focus-visible:ring-2 focus-visible:ring-current"
          >
            {t("chats.audio.open")}
          </a>
        </div>
        {meta && metaRow(null)}
      </div>
    )
  }

  return (
    <div className={cn("w-64 max-w-full", className)}>
      <div className="flex items-center gap-2.5">
      <audio
        ref={audioRef}
        src={src}
        preload="metadata"
        className="hidden"
        onLoadedMetadata={(event) => readDuration(event.currentTarget)}
        onDurationChange={(event) => readDuration(event.currentTarget)}
        onTimeUpdate={(event) => {
          if (!fixingDurationRef.current && !playing) setCurrent(event.currentTarget.currentTime)
        }}
        onPlay={handlePlay}
        onPlaying={() => setBuffering(false)}
        onWaiting={() => setBuffering(true)}
        onPause={() => {
          setPlaying(false)
          setBuffering(false)
        }}
        onEnded={handleEnded}
        onError={() => setFailed(true)}
      />

      <button
        type="button"
        onClick={togglePlay}
        aria-label={playing ? t("chats.audio.pause") : t("chats.audio.play")}
        title={playing ? t("chats.audio.pause") : t("chats.audio.play")}
        className={cn(
          "flex size-9 shrink-0 items-center justify-center rounded-full outline-none transition-transform active:scale-95 focus-visible:ring-2 focus-visible:ring-offset-2",
          onAccent
            ? "bg-primary-foreground text-primary focus-visible:ring-primary-foreground focus-visible:ring-offset-primary"
            : "bg-primary text-primary-foreground focus-visible:ring-ring focus-visible:ring-offset-background",
        )}
      >
        {buffering && playing ? (
          <Loader2 className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
        ) : playing ? (
          <Pause className="size-4 fill-current" aria-hidden="true" />
        ) : (
          // El triángulo se ve corrido a la izquierda si se centra por su caja: se compensa 1px.
          <Play className="ml-0.5 size-4 fill-current" aria-hidden="true" />
        )}
      </button>

      <div className="min-w-0 flex-1">
        {name && <p className="truncate text-xs font-medium" title={name}>{name}</p>}

        {/* Barra fina, pero con 20px de alto para tocarla; touch-pan-y deja seguir scrolleando el hilo. */}
        <div
          ref={trackRef}
          role="slider"
          tabIndex={seekable ? 0 : -1}
          aria-label={t("chats.audio.seek")}
          aria-valuemin={0}
          aria-valuemax={seekable ? Math.round(duration) : 0}
          aria-valuenow={Math.round(shownCurrent)}
          aria-valuetext={
            seekable
              ? t("chats.audio.position", { current: formatTime(shownCurrent), total: formatTime(duration) })
              : formatTime(shownCurrent)
          }
          aria-disabled={!seekable}
          onPointerDown={handlePointerDown}
          onPointerMove={handlePointerMove}
          onPointerUp={handlePointerUp}
          onPointerCancel={() => setDragRatio(null)}
          onKeyDown={handleTrackKeyDown}
          className={cn(
            "group/track relative flex h-5 touch-pan-y items-center rounded-full outline-none focus-visible:ring-2",
            seekable ? "cursor-pointer" : "cursor-default",
            onAccent ? "focus-visible:ring-primary-foreground" : "focus-visible:ring-ring",
          )}
        >
          <div className={cn("relative h-1 w-full overflow-hidden rounded-full", onAccent ? "bg-primary-foreground/30" : "bg-foreground/15")}>
            <div
              className={cn("absolute inset-0 origin-left rounded-full", onAccent ? "bg-primary-foreground" : "bg-primary")}
              style={{ transform: `scaleX(${ratio})` }}
            />
          </div>
          {/* El punto se mueve dentro de la barra (6px de margen a cada lado): en 0 y al final no queda medio afuera. */}
          <span
            aria-hidden="true"
            className={cn(
              "pointer-events-none absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full transition-transform group-hover/track:scale-125 group-focus-visible/track:scale-125",
              onAccent ? "bg-primary-foreground" : "bg-primary",
              dragRatio !== null && "scale-125",
            )}
            style={{ left: `calc(6px + ${ratio} * (100% - 12px))` }}
          />
        </div>
      </div>

      {/* La velocidad aparece una vez que arrancó, como en WhatsApp: antes es ruido. */}
      {started && (
        <button
          type="button"
          onClick={cycleRate}
          aria-label={t("chats.audio.speed", { rate: formatRate(rate) })}
          title={t("chats.audio.speed", { rate: formatRate(rate) })}
          className={cn(
            "h-6 min-w-10 shrink-0 rounded-full px-2 text-[11px] font-semibold tabular-nums outline-none focus-visible:ring-2",
            onAccent
              ? "bg-primary-foreground/20 focus-visible:ring-primary-foreground"
              : "bg-foreground/10 text-foreground focus-visible:ring-ring",
          )}
        >
          {formatRate(rate)}
        </button>
      )}
      </div>

      {/* Tiempo debajo de la barra (alineado con su inicio) y, a la derecha, la hora y el estado de la burbuja. */}
      <div className="pl-[2.875rem]">
        {metaRow(started ? `${formatTime(shownCurrent)} / ${formatTime(duration)}` : formatTime(duration))}
      </div>
    </div>
  )
}
