"use client"

import { useState } from "react"
import { Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { acknowledgeHumanHandoff } from "@/lib/api/conversations"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import type { Conversation } from "@/data/types"

export function HumanHandoffBanner({ conversation, onUpdated }: { conversation: Conversation; onUpdated: (conversation: Conversation) => void }) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const permissions = useAuthStore((state) => state.permissions)
  const [loading, setLoading] = useState(false)
  const handoff = conversation.humanHandoff
  if (!handoff) return null

  // Tomarla es comprometerse a responder: mismo permiso que escribir. El
  // backend además valida que la conversación sea alcanzable (branch/asignación).
  const canTake = (permissions ?? []).includes("conversations.send_message")

  async function take() {
    if (loading || !handoff) return
    setLoading(true)
    try {
      const updated = await acknowledgeHumanHandoff(conversation.id)
      const assignedTo = updated?.assigned_to ?? handoff.assigned_to
      onUpdated({
        ...conversation,
        assigneeId: assignedTo ?? conversation.assigneeId,
        humanHandoff: { ...handoff, status: "acknowledged", assigned_to: assignedTo },
      })
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.handoff.takeError"),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setLoading(false)
    }
  }

  const summary = handoff.summary || t("chats.handoff.defaultSummary")

  // Ya la tomó alguien: no pide nada, sólo deja a mano el motivo para quien responde.
  if (handoff.status === "acknowledged") {
    return (
      <p className="flex min-w-0 items-center gap-1.5 border-b border-border px-4 py-1.5 text-xs text-muted-foreground">
        <span className="shrink-0 font-medium text-foreground">{t("chats.handoff.takenTitle")}</span>
        <span aria-hidden="true">·</span>
        <span className="truncate" title={summary}>{summary}</span>
      </p>
    )
  }

  return (
    <div className="flex items-center justify-between gap-3 border-b border-warning/30 bg-warning/10 px-4 py-2 text-sm text-warning">
      <div className="min-w-0">
        <strong>{t("chats.handoff.title")}</strong>
        <div className="text-xs text-foreground">{summary}</div>
      </div>
      {canTake && (
        <Button size="sm" onClick={() => void take()} disabled={loading}>
          {loading && <Loader2 className="mr-1.5 size-4 animate-spin" />}
          {t("chats.handoff.take")}
        </Button>
      )}
    </div>
  )
}
