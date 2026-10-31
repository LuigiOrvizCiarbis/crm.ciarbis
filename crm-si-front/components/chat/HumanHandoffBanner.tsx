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

  return (
    <div className="mx-4 my-2 flex items-center justify-between gap-3 rounded-lg border border-amber-300/50 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
      <div className="min-w-0">
        <strong>{t("chats.handoff.title")}</strong>
        <div className="text-xs opacity-80">{handoff.summary || t("chats.handoff.defaultSummary")}</div>
      </div>
      {handoff.status === "pending" && canTake && (
        <Button size="sm" onClick={() => void take()} disabled={loading}>
          {loading && <Loader2 className="mr-1.5 size-4 animate-spin" />}
          {t("chats.handoff.take")}
        </Button>
      )}
      {handoff.status === "acknowledged" && (
        <span className="shrink-0 text-xs font-medium opacity-80">{t("chats.handoff.taken")}</span>
      )}
    </div>
  )
}
