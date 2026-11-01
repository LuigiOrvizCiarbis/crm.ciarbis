"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { CalendarClock, Check, Loader2, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { approveAiBooking, approveAiReschedule, getTasks, rejectAiBooking, rejectAiReschedule } from "@/lib/api/tasks"
import type { Task } from "@/lib/types/task"

interface PendingAiBookingsBannerProps {
  conversationId: number
  refreshKey: number
}

type RowKind = "booking" | "reschedule"
type Row = { kind: RowKind; task: Task }
type Decision = { key: string; action: "approve" | "reject" }

const rowKey = (row: Row) => `${row.kind}:${row.task.id}`

export function PendingAiBookingsBanner({ conversationId, refreshKey }: PendingAiBookingsBannerProps) {
  const { addToast } = useToast()
  const { t, language } = useTranslation()
  const [rows, setRows] = useState<Row[]>([])
  const [deciding, setDeciding] = useState<Decision | null>(null)

  const loadGeneration = useRef(0)
  const conversationGeneration = useRef(0)

  const loadPending = useCallback(async () => {
    const generation = ++loadGeneration.current
    try {
      const [bookings, reschedules] = await Promise.all([
        getTasks({ conversationId, aiBookingStatus: "pending" }),
        getTasks({ conversationId, aiReschedulePending: true }),
      ])
      if (generation === loadGeneration.current) {
        setRows([
          ...bookings.map((task): Row => ({ kind: "booking", task })),
          ...reschedules.map((task): Row => ({ kind: "reschedule", task })),
        ])
      }
    } catch {
      if (generation === loadGeneration.current) setRows([])
    }
  }, [conversationId])

  useEffect(() => {
    setRows([])
    setDeciding(null)
    return () => {
      conversationGeneration.current += 1
    }
  }, [conversationId])

  useEffect(() => {
    void loadPending()
    return () => {
      loadGeneration.current += 1
    }
  }, [loadPending, refreshKey])

  if (rows.length === 0) return null

  const handleDecision = async (row: Row, action: Decision["action"]) => {
    const generation = conversationGeneration.current
    const key = rowKey(row)
    const { task } = row
    setDeciding({ key, action })
    try {
      const { contactNotified } =
        row.kind === "booking"
          ? action === "approve"
            ? await approveAiBooking(task.id)
            : await rejectAiBooking(task.id)
          : action === "approve"
            ? await approveAiReschedule(task.id)
            : await rejectAiReschedule(task.id)
      if (generation !== conversationGeneration.current) return
      loadGeneration.current += 1
      setRows((prev) => prev.filter((item) => rowKey(item) !== key))
      void loadPending()
      const successKey =
        row.kind === "booking"
          ? action === "approve"
            ? "chats.aiBooking.approved"
            : "chats.aiBooking.rejected"
          : action === "approve"
            ? "chats.aiBooking.rescheduleApproved"
            : "chats.aiBooking.rescheduleRejected"
      addToast({ type: "success", title: t(successKey) })
      if (!contactNotified) {
        addToast({ type: "info", title: t("chats.aiBooking.notNotified") })
      }
    } catch (error) {
      if (generation !== conversationGeneration.current) return
      addToast({
        type: "error",
        title: t("common.error"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      if (generation === conversationGeneration.current) setDeciding(null)
    }
  }

  return (
    <div className="shrink-0 border-b border-border bg-amber-500/10 px-4 py-2">
      <div className="space-y-1.5">
        {rows.map((row) => {
          const { task } = row
          const key = rowKey(row)
          const formatter = new Intl.DateTimeFormat(language === "en" ? "en-US" : "es-AR", {
            timeZone: task.meetingTimezone ?? undefined,
            weekday: "long",
            day: "numeric",
            month: "numeric",
            hour: "2-digit",
            minute: "2-digit",
          })
          const format = (value?: string | null) => (value ? formatter.format(new Date(value)) : "")
          const message =
            row.kind === "booking"
              ? t("chats.aiBooking.proposed", { when: format(task.startsAt), assignee: task.assignee })
              : t("chats.aiBooking.rescheduleProposed", {
                  from: format(task.startsAt),
                  when: format(task.aiRescheduleStartsAt),
                  assignee: task.assignee,
                })
          const busy = deciding !== null
          return (
            <div key={key} className="flex flex-wrap items-center justify-between gap-2">
              <p className="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                <CalendarClock className="h-4 w-4 shrink-0" />
                <span>{message}</span>
              </p>
              <div className="flex items-center gap-1">
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  className="h-7 gap-1 px-2 text-xs"
                  disabled={busy}
                  onClick={() => handleDecision(row, "approve")}
                >
                  {deciding?.key === key && deciding.action === "approve" ? (
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
                  onClick={() => handleDecision(row, "reject")}
                >
                  {deciding?.key === key && deciding.action === "reject" ? (
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
