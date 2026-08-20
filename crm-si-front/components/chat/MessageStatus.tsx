"use client"

import { AlertCircle, Check, CheckCheck, Clock, Mic } from "lucide-react"
import { Message } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

type StatusKind = "pending" | "sent" | "delivered" | "read" | "played" | "failed"

/**
 * Deriva el estado por precedencia, nunca asumiendo que los webhooks llegaron en
 * orden: Meta documenta que `delivered` puede no enviarse nunca cuando el usuario
 * lee el mensaje con el chat abierto ("the 'delivered' webhook is not sent
 * because it's implied that the message was delivered since it was read").
 */
export function resolveMessageStatus(message: Message): StatusKind {
  if (message.failed_at) return "failed"
  if (message.played_at) return "played"
  if (message.read_at) return "read"
  if (message.delivered_at) return "delivered"
  // Un mensaje ya persistido salió del CRM: el piso es "enviado". El reloj queda
  // para envíos optimistas que todavía no tienen id del servidor.
  if (message.id) return "sent"
  return "pending"
}

interface MessageStatusProps {
  message: Message
  /** Sobre burbuja de acento el icono hereda el color del texto. */
  onAccent?: boolean
}

/**
 * Indicador de entrega para mensajes salientes. Los equivalentes de icono siguen
 * la referencia de Meta: un check (sent), doble check (delivered), doble check
 * resaltado (read), micrófono (played) y alerta (failed).
 */
export function MessageStatus({ message, onAccent = false }: MessageStatusProps) {
  const { t } = useTranslation()

  if (message.direction !== "outbound") return null

  const status = resolveMessageStatus(message)

  const formatTime = (value?: string | null) =>
    value
      ? new Date(value).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: true })
      : null

  // "Entregado" no significa "no lo leyó": si el contacto desactivó las
  // confirmaciones de lectura, el estado `read` no llega nunca aunque haya leído.
  const label: Record<StatusKind, string> = {
    pending: t("chats.statusPending"),
    sent: t("chats.statusSent"),
    delivered: t("chats.statusDelivered", { time: formatTime(message.delivered_at) ?? "" }).trim(),
    read: t("chats.statusRead", { time: formatTime(message.read_at) ?? "" }).trim(),
    played: t("chats.statusPlayed", { time: formatTime(message.played_at) ?? "" }).trim(),
    failed: message.error_message || t("chats.messageFailed"),
  }

  if (status === "failed") {
    // Sobre la burbuja de acento el rojo tiene casi la misma luminosidad que el
    // fondo y desaparece, así que ahí el icono se invierte: disco claro, glifo rojo.
    return (
      <span
        className={`inline-flex shrink-0 items-center justify-center ${
          onAccent
            ? "h-4 w-4 rounded-full bg-background text-destructive"
            : "text-destructive"
        }`}
        title={label.failed}
        aria-label={label.failed}
      >
        <AlertCircle className="h-3.5 w-3.5" />
      </span>
    )
  }

  const Icon = status === "pending" ? Clock : status === "played" ? Mic : status === "sent" ? Check : CheckCheck

  // El acento marca lectura. Sobre burbuja de acento no hay contraste posible,
  // así que ahí se usa opacidad plena contra el resto atenuado.
  const isAcknowledged = status === "read" || status === "played"
  const tone = onAccent
    ? isAcknowledged
      ? "opacity-100"
      : "opacity-60"
    : isAcknowledged
      ? "text-primary"
      : "text-muted-foreground"

  return (
    <span
      className={`inline-flex shrink-0 items-center ${tone}`}
      title={label[status]}
      aria-label={label[status]}
    >
      <Icon className="h-3.5 w-3.5" />
    </span>
  )
}
