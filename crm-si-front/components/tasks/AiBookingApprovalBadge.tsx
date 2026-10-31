"use client"

import { useState } from "react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Check, Loader2, Sparkles, X } from "lucide-react"
import { toast } from "sonner"
import type { Task } from "@/lib/types/task"
import { useTaskStore } from "@/store/useTaskStore"

interface AiBookingApprovalBadgeProps {
  task: Task
}

type PendingAction = "approve" | "reject" | null

export function AiBookingApprovalBadge({ task }: AiBookingApprovalBadgeProps) {
  const approveAiBooking = useTaskStore((state) => state.approveAiBooking)
  const rejectAiBooking = useTaskStore((state) => state.rejectAiBooking)
  const [pendingAction, setPendingAction] = useState<PendingAction>(null)

  if (task.aiBookingStatus !== "pending") return null

  const stop = (e: React.SyntheticEvent) => e.stopPropagation()

  const handleDecision = async (e: React.MouseEvent, action: "approve" | "reject") => {
    e.stopPropagation()
    setPendingAction(action)
    try {
      const contactNotified =
        action === "approve" ? await approveAiBooking(task.id) : await rejectAiBooking(task.id)
      toast.success(action === "approve" ? "Turno confirmado" : "Turno rechazado")
      if (!contactNotified) toast.warning("No se pudo avisar al cliente por el chat")
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo resolver el turno")
    } finally {
      setPendingAction(null)
    }
  }

  const busy = pendingAction !== null

  return (
    <div className="flex flex-wrap items-center gap-1.5" onClick={stop} onPointerDown={stop}>
      <Badge
        variant="outline"
        className="gap-1 text-xs font-medium bg-amber-500/15 text-amber-700 dark:text-amber-400 border-amber-500/30"
      >
        <Sparkles className="size-3" />
        Pendiente de aprobación
      </Badge>
      <Button
        size="sm"
        variant="ghost"
        className="h-6 gap-1 px-1.5 text-xs"
        disabled={busy}
        onPointerDown={stop}
        onClick={(e) => handleDecision(e, "approve")}
      >
        {pendingAction === "approve" ? <Loader2 className="size-3 animate-spin" /> : <Check className="size-3" />}
        Aceptar
      </Button>
      <Button
        size="sm"
        variant="ghost"
        className="h-6 gap-1 px-1.5 text-xs text-destructive hover:text-destructive"
        disabled={busy}
        onPointerDown={stop}
        onClick={(e) => handleDecision(e, "reject")}
      >
        {pendingAction === "reject" ? <Loader2 className="size-3 animate-spin" /> : <X className="size-3" />}
        Rechazar
      </Button>
    </div>
  )
}
