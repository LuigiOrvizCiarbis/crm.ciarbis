"use client"

import { StickyNote, ListTodo, MessageCircle, GitBranch } from "lucide-react"
import { cn } from "@/lib/utils"
import { useTranslation } from "@/hooks/useTranslation"
import type { TimelineEvent as TimelineEventData } from "@/lib/api/timeline"

const TYPE_CONFIG: Record<
  TimelineEventData["type"],
  { icon: typeof StickyNote; color: string }
> = {
  note: { icon: StickyNote, color: "text-warning bg-warning/10" },
  task: { icon: ListTodo, color: "text-info bg-info/10" },
  message: { icon: MessageCircle, color: "text-success bg-success/10" },
  // Etapas del embudo con el color de acción; el violeta queda para la IA.
  stage: { icon: GitBranch, color: "text-primary bg-primary/10" },
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
}

export function TimelineEvent({ event }: { event: TimelineEventData }) {
  const { t } = useTranslation()
  const config = TYPE_CONFIG[event.type]
  const Icon = config.icon

  return (
    <div className="flex gap-3">
      <div className={cn("flex h-9 w-9 shrink-0 items-center justify-center rounded-full", config.color)}>
        <Icon className="h-4 w-4" />
      </div>
      <div className="flex-1 min-w-0 rounded-lg border border-border bg-card p-3">
        <div className="flex items-center justify-between gap-2">
          <span className="text-sm font-medium">
            {event.type === "note" && (event.author ?? t("timeline.note"))}
            {event.type === "task" && (event.assignee ?? t("timeline.task"))}
            {event.type === "message" && (event.channel ?? t("timeline.message"))}
            {event.type === "stage" && t("timeline.stageChange")}
          </span>
          <span className="text-xs text-muted-foreground">{formatTime(event.occurredAt)}</span>
        </div>
        <div className="mt-1 text-sm text-muted-foreground break-words">
          {event.type === "note" && event.body}
          {event.type === "task" && (
            <span>
              <span className="font-medium text-foreground">{t("timeline.taskCreated")}: </span>
              {event.name}
            </span>
          )}
          {event.type === "message" && event.content}
          {event.type === "stage" && (
            <span>
              {event.title}
              {event.stage && <span className="font-medium text-foreground"> · {event.stage}</span>}
            </span>
          )}
        </div>
      </div>
    </div>
  )
}
