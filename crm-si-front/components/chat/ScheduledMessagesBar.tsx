"use client"

import { useState } from "react"
import { format } from "date-fns"
import { enUS, es } from "date-fns/locale"
import { AlertCircle, CalendarClock, ChevronDown, Image as ImageIcon, Loader2, Music2, Pencil, Trash2 } from "lucide-react"
import type { ScheduledMessage } from "@/data/types"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { useToast } from "@/components/Toast"
import { Button } from "@/components/ui/button"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Textarea } from "@/components/ui/textarea"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { cancelScheduledMessage, updateScheduledMessage } from "@/lib/api/scheduled-messages"
import { cn } from "@/lib/utils"
import { ScheduleMessagePicker } from "./ScheduleMessagePicker"

interface ScheduledMessagesBarProps {
  items: ScheduledMessage[]
  windowExpiresAt: string | null
  currentUserId?: number | null
  onChanged: (item: ScheduledMessage) => void
  onRemoved: (id: number) => void
}

/**
 * Chip sobre el composer con los mensajes programados del chat. Se abre en
 * una lista para editar el texto, reprogramar o cancelar cada uno. No se
 * mezclan con el hilo: recién aparecen ahí cuando salen (evento message.sent).
 */
export function ScheduledMessagesBar({ items, windowExpiresAt, currentUserId, onChanged, onRemoved }: ScheduledMessagesBarProps) {
  const { t, language } = useTranslation()
  const locale = language === "en" ? enUS : es
  const canManageOthers = usePermission("messages.update_any")
  const { addToast } = useToast()
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [cancelTarget, setCancelTarget] = useState<ScheduledMessage | null>(null)

  if (items.length === 0) return null

  const formatWhen = (iso: string) => format(new Date(iso), "EEE d MMM, HH:mm", { locale })
  const failedCount = items.filter((item) => item.status === "failed").length
  const nextPending = items.find((item) => item.status !== "failed")
  const canManage = (item: ScheduledMessage) => canManageOthers || item.user_id === currentUserId

  const handleReschedule = async (item: ScheduledMessage, scheduledFor: Date, content?: string) => {
    try {
      const updated = await updateScheduledMessage(item.id, { scheduledFor, content })
      onChanged(updated)
      setEditingId(null)
      addToast({ type: "success", title: t("chats.scheduledUpdatedToast", { when: formatWhen(updated.scheduled_for) }) })
    } catch (error) {
      addToast({ type: "error", title: error instanceof Error ? error.message : String(error) })
    }
  }

  const handleCancel = async (item: ScheduledMessage) => {
    try {
      await cancelScheduledMessage(item.id)
      onRemoved(item.id)
      addToast({ type: "success", title: t("chats.scheduledCancelledToast") })
    } catch (error) {
      addToast({ type: "error", title: error instanceof Error ? error.message : String(error) })
    }
  }

  return (
    <>
      <div className="flex justify-center px-3 pt-2 sm:px-4">
        <Popover open={open} onOpenChange={(next) => { setOpen(next); if (!next) setEditingId(null) }}>
          <PopoverTrigger asChild>
            <button
              type="button"
              className={cn(
                "inline-flex max-w-full items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none",
                failedCount > 0 ? "border-destructive/40 text-destructive" : "border-border text-muted-foreground",
              )}
            >
              <CalendarClock className="h-3.5 w-3.5 shrink-0" />
              <span className="truncate">
                {items.length === 1 ? t("chats.scheduledCountOne") : t("chats.scheduledCountOther", { count: items.length })}
                {nextPending ? ` · ${formatWhen(nextPending.scheduled_for)}` : ""}
                {failedCount > 0 ? ` · ${t("chats.scheduledFailedCount", { count: failedCount })}` : ""}
              </span>
              <ChevronDown className="h-3.5 w-3.5 shrink-0" />
            </button>
          </PopoverTrigger>
          <PopoverContent side="top" className="max-h-[70vh] w-[min(24rem,calc(100vw-2rem))] overflow-y-auto p-0">
            <p className="border-b px-3 py-2 text-sm font-medium">{t("chats.scheduledListTitle")}</p>
            <ul className="divide-y">
              {items.map((item) => (
                <ScheduledMessageItem
                  key={item.id}
                  item={item}
                  when={formatWhen(item.scheduled_for)}
                  manageable={canManage(item)}
                  editing={editingId === item.id}
                  windowExpiresAt={windowExpiresAt}
                  onEdit={() => setEditingId(editingId === item.id ? null : item.id)}
                  onCancel={() => setCancelTarget(item)}
                  onReschedule={(date, content) => handleReschedule(item, date, content)}
                />
              ))}
            </ul>
          </PopoverContent>
        </Popover>
      </div>

      <AlertDialog open={!!cancelTarget} onOpenChange={(next) => !next && setCancelTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("chats.scheduledCancelTitle")}</AlertDialogTitle>
            <AlertDialogDescription>{t("chats.scheduledCancelDescription")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("chats.scheduledKeep")}</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (cancelTarget) void handleCancel(cancelTarget)
                setCancelTarget(null)
              }}
            >
              {t("chats.scheduledCancel")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

interface ScheduledMessageItemProps {
  item: ScheduledMessage
  when: string
  manageable: boolean
  editing: boolean
  windowExpiresAt: string | null
  onEdit: () => void
  onCancel: () => void
  onReschedule: (scheduledFor: Date, content?: string) => Promise<void>
}

function ScheduledMessageItem({ item, when, manageable, editing, windowExpiresAt, onEdit, onCancel, onReschedule }: ScheduledMessageItemProps) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState(item.content ?? "")
  const isFailed = item.status === "failed"
  const isInFlight = item.status === "queued" || item.status === "sending"
  const MediaIcon = item.message_type === "image" ? ImageIcon : item.message_type === "audio" ? Music2 : null
  const contentChanged = draft.trim() !== (item.content ?? "")

  return (
    <li className="flex flex-col gap-2 px-3 py-2.5">
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1">
          <p className={cn("flex items-center gap-1 text-xs font-medium", isFailed ? "text-destructive" : "text-muted-foreground")}>
            {isFailed ? <AlertCircle className="h-3.5 w-3.5" /> : isInFlight ? <Loader2 className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" /> : <CalendarClock className="h-3.5 w-3.5" />}
            {isFailed ? t("chats.scheduledFailed") : isInFlight ? t("chats.scheduledSending") : when}
            {item.user?.name ? <span className="font-normal">· {t("chats.scheduledBy", { name: item.user.name })}</span> : null}
          </p>
          <p className="mt-0.5 line-clamp-2 text-sm break-words">
            {MediaIcon ? (
              <span className="mr-1 inline-flex items-center gap-1 text-muted-foreground">
                <MediaIcon className="h-3.5 w-3.5" />
                {item.message_type === "image" ? t("chats.scheduledImage") : t("chats.scheduledAudio")}
              </span>
            ) : null}
            {item.content}
          </p>
          {isFailed && item.error_message ? <p className="mt-1 text-xs text-destructive">{item.error_message}</p> : null}
        </div>
        {manageable && !isInFlight ? (
          <div className="flex shrink-0 gap-0.5">
            <Button variant="ghost" size="sm" className="h-7 w-7 p-0" onClick={onEdit} aria-label={isFailed ? t("chats.scheduledReschedule") : t("chats.scheduledEdit")} title={isFailed ? t("chats.scheduledReschedule") : t("chats.scheduledEdit")}>
              <Pencil className="h-3.5 w-3.5" />
            </Button>
            <Button variant="ghost" size="sm" className="h-7 w-7 p-0 text-destructive hover:text-destructive" onClick={onCancel} aria-label={t("chats.scheduledCancel")} title={t("chats.scheduledCancel")}>
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
          </div>
        ) : null}
      </div>

      {editing ? (
        <div className="flex flex-col gap-2 rounded-md bg-muted/40 p-2">
          {item.message_type !== "audio" ? (
            <>
              <Textarea
                aria-label={t("chats.scheduledEditContent")}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                className="min-h-16 resize-none text-sm [field-sizing:content]"
              />
              {!isFailed && contentChanged ? (
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={item.message_type === "text" && !draft.trim()}
                  onClick={() => onReschedule(new Date(item.scheduled_for), draft)}
                >
                  {t("chats.scheduledSaveText")}
                </Button>
              ) : null}
            </>
          ) : null}
          <ScheduleMessagePicker
            mode="save"
            initialDate={isFailed ? undefined : new Date(item.scheduled_for)}
            windowExpiresAt={windowExpiresAt}
            onConfirm={(date) => onReschedule(date, contentChanged ? draft : undefined)}
          />
        </div>
      ) : null}
    </li>
  )
}
