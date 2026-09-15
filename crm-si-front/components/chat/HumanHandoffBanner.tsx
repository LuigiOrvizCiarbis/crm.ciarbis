"use client"

import { Button } from "@/components/ui/button"
import { acknowledgeHumanHandoff } from "@/lib/api/conversations"
import type { Conversation } from "@/data/types"
import { useState } from "react"

export function HumanHandoffBanner({ conversation, onUpdated }: { conversation: Conversation; onUpdated: (conversation: Conversation) => void }) {
  const [loading, setLoading] = useState(false)
  const activeHandoff = conversation.humanHandoff
  if (!activeHandoff) return null
  const handoffSnapshot: NonNullable<Conversation["humanHandoff"]> = {
    id: activeHandoff.id,
    status: activeHandoff.status,
    reason: activeHandoff.reason,
    summary: activeHandoff.summary,
    assigned_to: activeHandoff.assigned_to,
    notifications: activeHandoff.notifications,
  }

  async function take() {
    if (loading) return
    setLoading(true)
    try {
      await acknowledgeHumanHandoff(conversation.id)
      onUpdated({
        ...conversation,
        humanHandoff: {
          id: handoffSnapshot.id,
          status: "acknowledged",
          reason: handoffSnapshot.reason,
          summary: handoffSnapshot.summary,
          assigned_to: handoffSnapshot.assigned_to,
          notifications: handoffSnapshot.notifications,
        },
      })
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="mx-4 my-2 flex items-center justify-between gap-3 rounded-lg border border-amber-300/50 bg-amber-50 px-3 py-2 text-sm text-amber-950">
      <div><strong>Derivación a humano</strong><div className="text-xs opacity-80">{activeHandoff.summary || "El cliente pidió hablar con una persona."}</div></div>
      {activeHandoff.status === "pending" && <Button size="sm" onClick={take} disabled={loading}>{loading ? "…" : "Tomar"}</Button>}
    </div>
  )
}
