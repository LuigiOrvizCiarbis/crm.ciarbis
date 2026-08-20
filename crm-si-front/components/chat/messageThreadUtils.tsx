"use client"

import { useState } from "react"
import { Music2 } from "lucide-react"
import { pauseOtherAudios } from "@/lib/audio"

/**
 * Helpers compartidos del hilo de mensajes: resaltado de búsqueda, etiquetas de
 * día, parseo de plantillas y los bloques de media de la burbuja. Viven acá para
 * que MessageList y MessageBubble los usen sin duplicar.
 */

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
}

/**
 * Resalta las coincidencias de `query` dentro de `text`.
 * Marca la coincidencia activa con un color distinto para la navegación.
 */
export function highlightText(
  text: string,
  query: string,
  activeKey: string | null,
  matchKeyPrefix: string,
): React.ReactNode {
  if (!query) return text
  const regex = new RegExp(`(${escapeRegExp(query)})`, "gi")
  const parts = text.split(regex)
  let matchIndex = 0
  return parts.map((part, i) => {
    if (part.toLowerCase() === query.toLowerCase()) {
      const key = `${matchKeyPrefix}-${matchIndex++}`
      const isActive = key === activeKey
      return (
        <mark
          key={i}
          data-match-key={key}
          className={
            isActive
              ? "rounded bg-orange-400 px-0.5 text-black"
              : "rounded bg-yellow-300/70 px-0.5 text-black"
          }
        >
          {part}
        </mark>
      )
    }
    return part
  })
}

export function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  )
}

/**
 * Etiqueta de día estilo WhatsApp: "Hoy", "Ayer", día de la semana si es de
 * los últimos 7 días, y fecha completa localizada para lo anterior.
 */
export function getDayLabel(
  date: Date,
  language: string,
  t: (key: string) => string,
): string {
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const diffDays = Math.round(
    (startOfDay(new Date()).getTime() - startOfDay(date).getTime()) / 86400000,
  )
  if (diffDays === 0) return t("chats.dateToday")
  if (diffDays === 1) return t("chats.dateYesterday")
  if (diffDays > 1 && diffDays < 7) {
    const weekday = date.toLocaleDateString(language, { weekday: "long" })
    return weekday.charAt(0).toUpperCase() + weekday.slice(1)
  }
  return date.toLocaleDateString(language, {
    day: "numeric",
    month: "long",
    year: "numeric",
  })
}

export function parseTemplateContent(content: string): { isTemplate: boolean; title: string; body: string } {
  const trimmed = (content || "").trim()
  if (!trimmed) return { isTemplate: false, title: "", body: "" }
  const withoutLeadingIcons = trimmed.replace(/^[^A-Za-z0-9]+/, "").trim()

  if (trimmed.startsWith("📋")) {
    const withoutIcon = trimmed.replace(/^📋\s*/, "")
    if (withoutIcon.includes("\n")) {
      const [firstLine, ...rest] = withoutIcon.split("\n")
      return {
        isTemplate: true,
        title: `📋 ${firstLine.trim()}`,
        body: rest.join("\n").trim(),
      }
    }

    const legacyWithBody = withoutIcon.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)\)$/i)
    if (legacyWithBody) {
      return {
        isTemplate: true,
        title: `📋 ${legacyWithBody[1].trim()}`,
        body: legacyWithBody[2].trim(),
      }
    }

    const legacyWithBodyNoClose = withoutIcon.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)$/i)
    if (legacyWithBodyNoClose) {
      return {
        isTemplate: true,
        title: `📋 ${legacyWithBodyNoClose[1].trim()}`,
        body: legacyWithBodyNoClose[2].trim(),
      }
    }

    const legacyNameOnly = withoutIcon.match(/^Template:\s*([\s\S]+)$/i)
    if (legacyNameOnly) {
      return {
        isTemplate: true,
        title: `📋 ${legacyNameOnly[1].trim()}`,
        body: "",
      }
    }

    return {
      isTemplate: true,
      title: `📋 ${withoutIcon.trim()}`,
      body: "",
    }
  }

  const plainLegacy = withoutLeadingIcons.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)\)$/i)
  if (plainLegacy) {
    return {
      isTemplate: true,
      title: `📋 ${plainLegacy[1].trim()}`,
      body: plainLegacy[2].trim(),
    }
  }

  const plainLegacyNoClose = withoutLeadingIcons.match(/^Template:\s*([^(]+)\s*\(([\s\S]+)$/i)
  if (plainLegacyNoClose) {
    return {
      isTemplate: true,
      title: `📋 ${plainLegacyNoClose[1].trim()}`,
      body: plainLegacyNoClose[2].trim(),
    }
  }

  const plainNameOnly = withoutLeadingIcons.match(/^Template:\s*([\s\S]+)$/i)
  if (plainNameOnly) {
    return {
      isTemplate: true,
      title: `📋 ${plainNameOnly[1].trim()}`,
      body: "",
    }
  }

  return { isTemplate: false, title: "", body: "" }
}

function ImageLightbox({ src, onClose }: { src: string; onClose: () => void }) {
  return (
    <div
      className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center cursor-pointer"
      onClick={onClose}
    >
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={src}
        alt="Imagen completa"
        className="max-w-[90vw] max-h-[90vh] object-contain rounded-lg"
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  )
}

export function MessageBubbleImage({ mediaUrl, isUser }: { mediaUrl: string; isUser: boolean }) {
  const [lightboxOpen, setLightboxOpen] = useState(false)

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={mediaUrl}
        alt="Imagen"
        className={`rounded-lg max-w-[240px] max-h-[240px] object-cover cursor-pointer hover:opacity-90 transition-opacity ${
          isUser ? "bg-primary/20" : "bg-muted"
        }`}
        onClick={() => setLightboxOpen(true)}
        loading="lazy"
      />
      {lightboxOpen && <ImageLightbox src={mediaUrl} onClose={() => setLightboxOpen(false)} />}
    </>
  )
}

export function MessageBubbleSticker({ mediaUrl }: { mediaUrl: string }) {
  const [lightboxOpen, setLightboxOpen] = useState(false)

  return (
    <>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={mediaUrl}
        alt="Sticker"
        className="max-w-[160px] max-h-[160px] object-contain cursor-pointer hover:opacity-90 transition-opacity"
        onClick={() => setLightboxOpen(true)}
        loading="lazy"
      />
      {lightboxOpen && <ImageLightbox src={mediaUrl} onClose={() => setLightboxOpen(false)} />}
    </>
  )
}

export function MessageBubbleAudio({ mediaUrl, filename }: { mediaUrl: string; filename?: string | null }) {
  return (
    <div className="space-y-2 min-w-[220px]">
      <div className="flex items-center gap-2 text-xs opacity-80">
        <Music2 className="h-4 w-4 shrink-0" />
        <span className="truncate">{filename || "Audio"}</span>
      </div>
      <audio controls src={mediaUrl} className="w-full max-w-[280px]" preload="metadata" onPlay={pauseOtherAudios} />
    </div>
  )
}
