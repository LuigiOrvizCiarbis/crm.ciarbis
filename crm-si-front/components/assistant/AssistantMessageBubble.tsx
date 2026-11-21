"use client"

import type { UIMessage } from "ai"
import { ThumbsDown, ThumbsUp } from "lucide-react"
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

export function AssistantMessageBubble({
  message,
  rating,
  onRate,
}: {
  message: UIMessage
  rating?: AssistantRating
  /** Ausente mientras el mensaje no está guardado (todavía en streaming). */
  onRate?: (rating: AssistantRating) => void
}) {
  const { t } = useTranslation()
  const text = messageText(message)

  if (message.role === "user") {
    return (
      <div className="flex justify-end">
        <p className="bg-primary text-primary-foreground max-w-[85%] rounded-2xl rounded-br-sm px-3 py-2 text-sm whitespace-pre-wrap">
          {text}
        </p>
      </div>
    )
  }

  return (
    <div className="flex flex-col items-start gap-1">
      {isRunningTool(message) ? (
        <span className="text-muted-foreground bg-muted/60 rounded-full px-2.5 py-0.5 text-xs">
          {t("assistant.toolRunning")}
        </span>
      ) : null}
      {text ? (
        <div className="bg-muted max-w-[90%] rounded-2xl rounded-bl-sm px-3 py-2 text-sm">
          <RichText text={text} dialect="flow" />
        </div>
      ) : null}
      {onRate && text ? (
        <div className="flex gap-0.5">
          <RatingButton
            label={t("assistant.feedbackUp")}
            active={rating === 1}
            onClick={() => onRate(1)}
            icon={<ThumbsUp className="size-3.5" aria-hidden="true" />}
          />
          <RatingButton
            label={t("assistant.feedbackDown")}
            active={rating === -1}
            onClick={() => onRate(-1)}
            icon={<ThumbsDown className="size-3.5" aria-hidden="true" />}
          />
        </div>
      ) : null}
    </div>
  )
}

function RatingButton({
  label,
  active,
  onClick,
  icon,
}: {
  label: string
  active: boolean
  onClick: () => void
  icon: React.ReactNode
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      aria-label={label}
      aria-pressed={active}
      title={label}
      onClick={onClick}
      className={cn("size-7", active ? "text-primary" : "text-muted-foreground")}
    >
      {icon}
    </Button>
  )
}
