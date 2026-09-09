"use client"

import { SmilePlus } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"

/** Las seis reacciones rápidas de WhatsApp, todas presentes en EMOJI_GROUPS. */
export const QUICK_REACTIONS = ["👍", "❤️", "😂", "😮", "😢", "🙏"] as const

interface ReactionPickerProps {
  onSelect: (emoji: string) => void
  onMore: () => void
  /** Reacción propia actual: se resalta y tocarla de nuevo la quita. */
  currentEmoji?: string | null
  size?: "compact" | "touch"
}

/**
 * Fila rápida de reacciones + botón para abrir el EmojiPicker completo. Sin
 * contenedor propio, igual que EmojiPicker: el padre decide si va dentro de
 * un DropdownMenu, un Popover o un Sheet.
 */
export function ReactionPicker({ onSelect, onMore, currentEmoji, size = "compact" }: ReactionPickerProps) {
  const { t } = useTranslation()
  const isTouch = size === "touch"

  return (
    <div className={`flex items-center gap-0.5 ${isTouch ? "justify-around px-2" : "px-1"}`}>
      {QUICK_REACTIONS.map((emoji) => {
        const mine = emoji === currentEmoji
        return (
          <button
            key={emoji}
            type="button"
            onClick={() => onSelect(mine ? "" : emoji)}
            aria-pressed={mine}
            aria-label={emoji}
            className={`flex items-center justify-center rounded-full text-lg transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 ${
              isTouch ? "h-11 w-11 text-2xl" : "h-8 w-8"
            } ${mine ? "bg-primary/10" : ""}`}
          >
            {emoji}
          </button>
        )
      })}
      <button
        type="button"
        onClick={onMore}
        aria-label={t("chats.moreEmojis")}
        className={`flex items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 ${
          isTouch ? "h-11 w-11" : "h-8 w-8"
        }`}
      >
        <SmilePlus className={isTouch ? "h-5 w-5" : "h-4 w-4"} />
      </button>
    </div>
  )
}
