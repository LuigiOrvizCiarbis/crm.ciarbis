"use client"

import Link from "next/link"
import { AlignLeft, Bell, CalendarDays, Copy, ExternalLink, Link2, Pencil, Trash2, UserRound, Users, Video } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { CalendarEventAction, CalendarEventPopover, CalendarEventRow, formatEventWhen } from "@/components/calendar/CalendarEventPopover"
import { useTranslation } from "@/hooks/useTranslation"
import type { Task, TaskPriority } from "@/lib/types/task"
import { AiBookingApprovalBadge } from "./AiBookingApprovalBadge"
import { CalendarSyncBadge, showsCalendarSync } from "./CalendarSyncBadge"
import { statusLabels, typeLabels } from "./task-labels"
import { useDeleteTaskWithUndo } from "./useDeleteTaskWithUndo"

// Mismo matiz que el chip de la tarea en el calendario, en sólido para el cuadro.
const prioritySwatch: Record<TaskPriority, string> = {
  baja: "bg-gray-500",
  media: "bg-blue-500",
  alta: "bg-orange-500",
  critica: "bg-red-500",
}

// Coincide con el aviso que el back le pone al recordatorio en Google
// (SyncTaskCalendarEventJob::reminderEvent).
const REMINDER_POPUP_MINUTES = 10

export interface TaskEventPopoverProps {
  task: Task
  anchor: HTMLElement
  open: boolean
  onOpenChange: (open: boolean) => void
  onEdit: (task: Task) => void
}

/** Detalle de una tarea del CRM en el calendario, con la misma tarjeta que los eventos de Google. */
export function TaskEventPopover({ task, anchor, open, onOpenChange, onEdit }: TaskEventPopoverProps) {
  const { t, language } = useTranslation()
  const tr = (key: string, params?: Record<string, string | number>) => t(`calendarEvents.${key}`, params)
  const { addToast } = useToast()
  const deleteWithUndo = useDeleteTaskWithUndo()

  const isMeeting = task.type === "reunion"
  const start = new Date((isMeeting && task.startsAt) || task.deadline!)
  const end = isMeeting && task.startsAt && task.endsAt ? new Date(task.endsAt) : null
  const sync = task.calendarSync
  const meetLink = sync?.meetLink ?? null

  async function copyMeetLink(link: string) {
    try {
      await navigator.clipboard.writeText(link)
      addToast({ type: "success", title: tr("meetCopied") })
    } catch {
      addToast({ type: "error", title: tr("copyFailed") })
    }
  }

  return (
    <CalendarEventPopover
      anchor={anchor}
      open={open}
      onOpenChange={onOpenChange}
      closeLabel={tr("close")}
      swatchClassName={prioritySwatch[task.priority]}
      title={task.name}
      when={formatEventWhen(start, end, language)}
      subtitle={`${typeLabels[task.type]} · ${statusLabels[task.status]}`}
      actions={
        <>
          <CalendarEventAction label={tr("editTask")} onClick={() => onEdit(task)}>
            <Pencil aria-hidden="true" className="size-5" />
          </CalendarEventAction>
          <CalendarEventAction
            label={tr("deleteTask")}
            onClick={() => {
              onOpenChange(false)
              deleteWithUndo(task.id)
            }}
          >
            <Trash2 aria-hidden="true" className="size-5" />
          </CalendarEventAction>
          {sync?.htmlLink && (
            <CalendarEventAction label={tr("openGoogle")} asChild>
              <a href={sync.htmlLink} target="_blank" rel="noopener noreferrer">
                <ExternalLink aria-hidden="true" className="size-5" />
              </a>
            </CalendarEventAction>
          )}
        </>
      }
    >
      {meetLink && (
        <CalendarEventRow icon={Video} label={tr("rows.meet")}>
          <div className="flex items-start gap-2">
            <div className="min-w-0 flex-1 space-y-1.5">
              <Button asChild className="min-h-10 px-5">
                <a href={meetLink} target="_blank" rel="noopener noreferrer">{tr("joinMeet")}</a>
              </Button>
              <p className="truncate text-xs text-muted-foreground">{meetLink.replace(/^https?:\/\//, "")}</p>
            </div>
            <CalendarEventAction label={tr("copyMeet")} onClick={() => void copyMeetLink(meetLink)}>
              <Copy aria-hidden="true" className="size-5" />
            </CalendarEventAction>
          </div>
        </CalendarEventRow>
      )}

      {isMeeting && task.meetingGuestEmail && (
        <CalendarEventRow icon={Users} label={tr("rows.guest")}>
          <p className="break-all">{task.meetingGuestEmail}</p>
        </CalendarEventRow>
      )}

      {!isMeeting && sync?.status === "synced" && (
        <CalendarEventRow icon={Bell} label={tr("rows.reminder")}>
          <p>{tr("reminderBefore", { minutes: REMINDER_POPUP_MINUTES })}</p>
        </CalendarEventRow>
      )}

      {task.relatedTo && (
        <CalendarEventRow icon={Link2} label={tr("rows.related")}>
          {task.relatedTo.kind === "chat" ? (
            <Link href={`/chats?chat=${task.relatedTo.id}`} className="text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
              {tr(`related.${task.relatedTo.kind}`)} · {task.relatedTo.label}
            </Link>
          ) : (
            <p className="truncate">{tr(`related.${task.relatedTo.kind}`)} · {task.relatedTo.label}</p>
          )}
        </CalendarEventRow>
      )}

      {task.description && (
        <CalendarEventRow icon={AlignLeft} label={tr("rows.description")}>
          <p className="line-clamp-6 whitespace-pre-wrap break-words text-muted-foreground">{task.description}</p>
        </CalendarEventRow>
      )}

      <CalendarEventRow icon={UserRound} label={tr("rows.assignee")}>
        <p>{task.assignee || tr("unassigned")}</p>
      </CalendarEventRow>

      {showsCalendarSync(task) && (
        <CalendarEventRow icon={CalendarDays} label={tr("rows.sync")}>
          <div className="space-y-1.5">
            <CalendarSyncBadge taskId={task.id} sync={sync} showMeetLink={false} />
            <AiBookingApprovalBadge task={task} />
          </div>
        </CalendarEventRow>
      )}
    </CalendarEventPopover>
  )
}
