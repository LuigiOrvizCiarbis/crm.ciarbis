"use client"

import { useEffect, useState } from "react"
import { CalendarClock, Check, Loader2, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { approveAiBooking, getTasks, rejectAiBooking } from "@/lib/api/tasks"
import type { Task } from "@/lib/types/task"

interface PendingAiBookingsBannerProps {
  conversationId: number
  refreshKey: number
}

type Decision = { taskId: string; action: "approve" | "reject" }

export function PendingAiBookingsBanner({ conversationId, refreshKey }: PendingAiBookingsBannerProps) {
  const { addToast } = useToast()
  const { t, language } = useTranslation()
  const [tasks, setTasks] = useState<Task[]>([])
  const [deciding, setDeciding] = useState<Decision | null>(null)

  useEffect(() => {
    let cancelled = false
    getTasks({ conversationId, aiBookingStatus: "pending" })
      .then((pending) => {
        if (!cancelled) setTasks(pending)
      })
      .catch(() => {
        if (!cancelled) setTasks([])
      })
    return () => {
      cancelled = true
    }
  }, [conversationId, refreshKey])

  if (tasks.length === 0) return null

  const handleDecision = async (task: Task, action: Decision["action"]) => {
    setDeciding({ taskId: task.id, action })
    try {
      const { contactNotified } =
        action === "approve" ? await approveAiBooking(task.id) : await rejectAiBooking(task.id)
      setTasks((prev) => prev.filter((item) => item.id !== task.id))
      addToast({
        type: "success",
        title: t(action === "approve" ? "chats.aiBooking.approved" : "chats.aiBooking.rejected"),
      })
      if (!contactNotified) {
        addToast({ type: "info", title: t("chats.aiBooking.notNotified") })
      }
    } catch (error) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setDeciding(null)
    }
  }

  return (
    <div className="shrink-0 border-b border-border bg-amber-500/10 px-4 py-2">
      <div className="space-y-1.5">
        {tasks.map((task) => {
          const when = task.startsAt
            ? new Intl.DateTimeFormat(language === "en" ? "en-US" : "es-AR", {
                timeZone: task.meetingTimezone ?? undefined,
                weekday: "long",
                day: "numeric",
                month: "numeric",
                hour: "2-digit",
                minute: "2-digit",
              }).format(new Date(task.startsAt))
            : ""
          const busy = deciding !== null
          return (
            <div key={task.id} className="flex flex-wrap items-center justify-between gap-2">
              <p className="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                <CalendarClock className="h-4 w-4 shrink-0" />
                <span>{t("chats.aiBooking.proposed", { when, assignee: task.assignee })}</span>
              </p>
              <div className="flex items-center gap-1">
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  className="h-7 gap-1 px-2 text-xs"
                  disabled={busy}
                  onClick={() => handleDecision(task, "approve")}
                >
                  {deciding?.taskId === task.id && deciding.action === "approve" ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Check className="h-3.5 w-3.5" />
                  )}
                  {t("chats.aiBooking.accept")}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  className="h-7 gap-1 px-2 text-xs text-destructive hover:text-destructive"
                  disabled={busy}
                  onClick={() => handleDecision(task, "reject")}
                >
                  {deciding?.taskId === task.id && deciding.action === "reject" ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <X className="h-3.5 w-3.5" />
                  )}
                  {t("chats.aiBooking.reject")}
                </Button>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
