"use client"

import { useState } from "react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Check, Loader2, Sparkles, X } from "lucide-react"
import { toast } from "sonner"
import type { Task } from "@/lib/types/task"
import { useTranslation } from "@/hooks/useTranslation"
import { useTaskStore } from "@/store/useTaskStore"

interface AiBookingApprovalBadgeProps {
  task: Task
}

type PendingAction = "approve" | "reject" | null

export function AiBookingApprovalBadge({ task }: AiBookingApprovalBadgeProps) {
  const { t, language } = useTranslation()
  const tr = (key: string, params?: Record<string, string | number>) => t(`chats.aiBooking.${key}`, params)
  const approveAiBooking = useTaskStore((state) => state.approveAiBooking)
  const rejectAiBooking = useTaskStore((state) => state.rejectAiBooking)
  const approveAiReschedule = useTaskStore((state) => state.approveAiReschedule)
  const rejectAiReschedule = useTaskStore((state) => state.rejectAiReschedule)
  const [pendingAction, setPendingAction] = useState<PendingAction>(null)

  const isReschedule = Boolean(task.aiRescheduleStartsAt)
  if (task.aiBookingStatus !== "pending" && !isReschedule) return null

  const stop = (e: React.SyntheticEvent) => e.stopPropagation()

  const handleDecision = async (e: React.MouseEvent, action: "approve" | "reject") => {
    e.stopPropagation()
    setPendingAction(action)
    try {
      const contactNotified = isReschedule
        ? action === "approve"
          ? await approveAiReschedule(task.id)
          : await rejectAiReschedule(task.id)
        : action === "approve"
          ? await approveAiBooking(task.id)
          : await rejectAiBooking(task.id)
      toast.success(
        isReschedule
          ? action === "approve"
            ? tr("rescheduleApproved")
            : tr("rescheduleRejected")
          : action === "approve"
            ? tr("approved")
            : tr("rejected"),
      )
      if (!contactNotified) toast.warning(tr("notNotified"))
    } catch (error) {
      toast.error(error instanceof Error ? error.message : tr("resolveError"))
    } finally {
      setPendingAction(null)
    }
  }

  const label = task.aiRescheduleStartsAt
    ? tr("rescheduleRequested", {
        when: new Intl.DateTimeFormat(language === "en" ? "en-US" : "es-AR", {
          timeZone: task.meetingTimezone ?? undefined,
          dateStyle: "short",
          timeStyle: "short",
        }).format(new Date(task.aiRescheduleStartsAt)),
      })
    : tr("pending")

  const busy = pendingAction !== null

  return (
    <div className="flex flex-wrap items-center gap-1.5" onClick={stop} onPointerDown={stop}>
      <Badge
        variant="outline"
        className="gap-1 text-xs font-medium bg-amber-500/15 text-amber-700 dark:text-amber-400 border-amber-500/30"
      >
        <Sparkles className="size-3" />
        {label}
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
        {tr("accept")}
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
        {tr("reject")}
      </Button>
    </div>
  )
}
