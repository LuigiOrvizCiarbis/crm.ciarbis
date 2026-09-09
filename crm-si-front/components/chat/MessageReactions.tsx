"use client"

import { MessageReaction } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

interface MessageReactionsProps {
  reactions: MessageReaction[]
  currentUserId?: number
  onToggle: (emoji: string) => void
  disabled?: boolean
}

/**
 * Fila de chips de reacción agregada, hermana de la burbuja (no absolute):
 * así el layout de mensajes agrupados no se pisa cuando hay varias.
 * Presentacional puro — el toggle y el estado optimista viven en el padre.
 */
export function MessageReactions({ reactions, currentUserId, onToggle, disabled = false }: MessageReactionsProps) {
  const { t } = useTranslation()

  if (reactions.length === 0) return null

  return (
    <div className="-mt-1 flex flex-wrap gap-1 px-1">
      {reactions.map((r) => {
        const mine = !!currentUserId && (r.reactor_user_ids ?? []).includes(currentUserId)
        return (
          <button
            key={r.emoji}
            type="button"
            disabled={disabled}
            onClick={() => onToggle(mine ? "" : r.emoji)}
            aria-pressed={mine}
            aria-label={
              mine
                ? t("chats.reactionRemoveAria", { emoji: r.emoji })
                : t("chats.reactionAria", { emoji: r.emoji, count: String(r.count) })
            }
            className={`flex items-center gap-0.5 rounded-full border px-1.5 py-0.5 text-xs transition-colors focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-60 ${
              mine
                ? "border-primary bg-primary/10"
                : "border-border bg-background hover:bg-accent"
            }`}
          >
            <span className="text-sm leading-none">{r.emoji}</span>
            {r.count > 1 ? <span className="tabular-nums text-muted-foreground">{r.count}</span> : null}
          </button>
        )
      })}
    </div>
  )
}
