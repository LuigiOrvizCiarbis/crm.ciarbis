import type { SyntheticEvent } from "react"

/**
 * Pausa cualquier otro <audio> de la página cuando uno empieza a reproducirse,
 * para que nunca suenen dos audios en simultáneo.
 */
export function pauseOtherAudios(e: SyntheticEvent<HTMLAudioElement>) {
  document.querySelectorAll("audio").forEach((el) => {
    if (el !== e.currentTarget && !el.paused) el.pause()
  })
}

/**
 * Candidatos de mime para grabar, en orden de preferencia. audio/mp4 primero:
 * es lo que graba Safari/iOS nativamente y evita la transcodificación del
 * backend (WhatsApp acepta mp4/aac directo). El resto son fallbacks de Chrome
 * y Firefox, que no soportan mp4 en MediaRecorder.
 */
const RECORDER_MIME_CANDIDATES = [
  "audio/mp4",
  "audio/ogg;codecs=opus",
  "audio/webm;codecs=opus",
  "audio/webm",
] as const

/**
 * Elige el mejor mime soportado por MediaRecorder en este navegador, o `null`
 * si no hay ninguno (se usa el default del browser sin fijar mimeType).
 */
export function pickRecorderMimeType(): string | null {
  if (typeof MediaRecorder === "undefined" || !MediaRecorder.isTypeSupported) {
    return null
  }

  return RECORDER_MIME_CANDIDATES.find((mime) => MediaRecorder.isTypeSupported(mime)) ?? null
}

/**
 * Extensión de archivo coherente con el mime grabado/adjuntado, para que el
 * backend nombre bien el archivo que sube a Meta (usa basename()).
 */
export function audioExtensionForMime(mime: string): string {
  const base = mime.split(";")[0]?.trim().toLowerCase()
  switch (base) {
    case "audio/mp4":
    case "audio/x-m4a":
      return "m4a"
    case "audio/ogg":
      return "ogg"
    case "audio/webm":
    case "video/webm":
      return "webm"
    case "audio/wav":
    case "audio/x-wav":
      return "wav"
    case "audio/aac":
      return "aac"
    case "audio/amr":
    case "audio/3gpp":
      return "amr"
    case "audio/mpeg":
    case "audio/mp3":
    default:
      return "mp3"
  }
}

/** true si el navegador puede grabar audio: MediaRecorder + contexto seguro (https). */
export function canRecordAudio(): boolean {
  if (typeof window === "undefined") return false
  if (typeof MediaRecorder === "undefined") return false
  if (!navigator.mediaDevices?.getUserMedia) return false
  return window.isSecureContext
}
