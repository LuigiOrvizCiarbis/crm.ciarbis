"use client"

import type { UIMessage } from "ai"
import { Loader2, ThumbsDown, ThumbsUp } from "lucide-react"
import { RichText } from "@/components/rich-text/RichText"
import { Button } from "@/components/ui/button"
import { useTranslation } from "@/hooks/useTranslation"
import type { AssistantRating } from "@/lib/api/assistant"
import { cn } from "@/lib/utils"

/** Texto visible del mensaje: las partes de razonamiento y herramientas no se muestran. */
export function messageText(message: UIMessage): string {
  return message.parts
    .map((part) => (part.type === "text" ? part.text : ""))
    .join("")
}

export function isRunningTool(message: UIMessage): boolean {
  return message.parts.some(
    (part) =>
      part.type.startsWith("tool-") &&
      "state" in part &&
      part.state !== "output-available" &&
      part.state !== "output-error",
  )
}

/**
 * El asistente no es una conversación con un contacto: la pregunta va en un bloque
 * neutro y la respuesta se lee como texto corrido, sin la burbuja de la bandeja.
 */
export function AssistantMessageBubble({
  message,
  rating,
  onRate,
  ratingDisabled = false,
  showActions = false,
}: {
  message: UIMessage
  rating?: AssistantRating
  /** Ausente mientras el mensaje no está guardado (todavía en streaming). */
  onRate?: (rating: AssistantRating) => void
  /** Durante un turno la fila sigue en su lugar (sin saltos de layout) pero no acepta clics. */
  ratingDisabled?: boolean
  /** Acciones siempre visibles (última respuesta); en el resto aparecen al pasar o enfocar. */
  showActions?: boolean
}) {
  const { t } = useTranslation()
  const text = messageText(message)

  if (message.role === "user") {
    return (
      <div className="flex justify-end not-first:mt-1">
        <p className="bg-muted text-foreground max-w-[85%] rounded-lg px-3 py-2 text-sm break-words whitespace-pre-wrap">
          {text}
        </p>
      </div>
    )
  }

  return (
    <div className="group/message flex flex-col items-start gap-1">
      {isRunningTool(message) ? (
        <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
          <Loader2 className="size-3.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
          {t("assistant.toolRunning")}
        </span>
      ) : null}
      {text ? (
        <div className="w-full text-sm leading-relaxed break-words">
          <RichText text={text} dialect="flow" />
        </div>
      ) : null}
      {onRate && text ? (
        <div
          className={cn(
            "-ml-1.5 flex gap-0.5 transition-opacity duration-150 ease-out",
            // Sin hover (táctil) quedan siempre visibles.
            !showActions &&
              !rating &&
              "[@media(hover:hover)]:opacity-0 group-focus-within/message:opacity-100 group-hover/message:opacity-100",
          )}
        >
          <RatingButton
            label={t("assistant.feedbackUp")}
            active={rating === 1}
            disabled={ratingDisabled}
            onClick={() => onRate(1)}
            icon={ThumbsUp}
          />
          <RatingButton
            label={t("assistant.feedbackDown")}
            active={rating === -1}
            disabled={ratingDisabled}
            onClick={() => onRate(-1)}
            icon={ThumbsDown}
          />
        </div>
      ) : null}
    </div>
  )
}

function RatingButton({
  label,
  active,
  disabled,
  onClick,
  icon: Icon,
}: {
  label: string
  active: boolean
  disabled: boolean
  onClick: () => void
  icon: typeof ThumbsUp
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      aria-label={label}
      aria-pressed={active}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className={cn(
        "size-7 pointer-coarse:size-10",
        active ? "text-primary" : "text-muted-foreground hover:text-foreground",
      )}
    >
      <Icon className={cn("size-3.5", active && "fill-current/20")} aria-hidden="true" />
    </Button>
  )
}
