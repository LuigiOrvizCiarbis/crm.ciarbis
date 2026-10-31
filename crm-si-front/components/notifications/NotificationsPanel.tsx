"use client"

import { useMemo } from "react"
import { useRouter } from "next/navigation"
import { formatDistanceToNow } from "date-fns"
import { enUS, es } from "date-fns/locale"
import {
  AlertTriangle,
  Bell,
  CalendarX,
  CheckCheck,
  ClipboardCheck,
  Clock,
  Hand,
  Loader2,
  MessageSquare,
  PlugZap,
  Upload,
  UserMinus,
  UserPlus,
  type LucideIcon,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { useTranslation } from "@/hooks/useTranslation"
import type { AppNotification, NotificationType } from "@/lib/api/notifications"
import { describeNotification } from "@/lib/notificationPresentation"
import { cn } from "@/lib/utils"
import { useNotificationsStore } from "@/store/useNotificationsStore"

const TYPE_ICONS: Record<NotificationType, LucideIcon> = {
  "message.received": MessageSquare,
  "conversation.assigned": UserPlus,
  "conversation.unassigned": UserMinus,
  "handoff.requested": Hand,
  "task.assigned": ClipboardCheck,
  "import.completed": Upload,
  "import.failed": AlertTriangle,
  "channel.token_invalid": PlugZap,
  "trial.expiring": Clock,
  "scheduled_message.failed": CalendarX,
}

const ATTENTION_TYPES = new Set<NotificationType>(["handoff.requested", "import.failed", "channel.token_invalid", "trial.expiring", "scheduled_message.failed"])

interface NotificationsPanelProps {
  /** Se llama al abrir una notificación (para cerrar el popover/sheet). */
  onNavigate?: () => void
  className?: string
  /** Ej. dejar lugar al botón de cerrar de un Sheet. */
  headerClassName?: string
}

export function NotificationsPanel({ onNavigate, className, headerClassName }: NotificationsPanelProps) {
  const { t, language } = useTranslation()
  const router = useRouter()
  const items = useNotificationsStore((state) => state.items)
  const unreadCount = useNotificationsStore((state) => state.unreadCount)
  const nextCursor = useNotificationsStore((state) => state.nextCursor)
  const loading = useNotificationsStore((state) => state.loading)
  const loaded = useNotificationsStore((state) => state.loaded)
  const markRead = useNotificationsStore((state) => state.markRead)
  const markAllRead = useNotificationsStore((state) => state.markAllRead)
  const loadMore = useNotificationsStore((state) => state.loadMore)
  const dateLocale = language === "en" ? enUS : es

  const open = (notification: AppNotification) => {
    markRead(notification.id).catch(() => {})
    onNavigate?.()
    if (notification.data?.url) router.push(notification.data.url)
  }

  const rows = useMemo(
    () => items.map((notification) => ({ notification, text: describeNotification(notification, t) })),
    [items, t],
  )

  return (
    <div className={cn("flex min-h-0 flex-col", className)}>
      <div className={cn("flex items-center justify-between gap-2 border-b px-4 py-3", headerClassName)}>
        <div className="flex min-w-0 items-baseline gap-2">
          <h2 className="truncate text-sm font-semibold">{t("notifications.title")}</h2>
          {unreadCount > 0 ? (
            <span className="whitespace-nowrap text-xs text-muted-foreground tabular-nums">{t("notifications.unread", { count: unreadCount })}</span>
          ) : null}
        </div>
        <Button
          variant="ghost"
          size="sm"
          className="h-7 shrink-0 gap-1.5 px-2 text-xs"
          disabled={unreadCount === 0}
          onClick={() => markAllRead().catch(() => {})}
        >
          <CheckCheck className="size-3.5" />
          {t("notifications.markAllRead")}
        </Button>
      </div>

      {!loaded && loading ? (
        <div className="flex items-center justify-center py-10 text-muted-foreground">
          <Loader2 className="size-4 animate-spin" />
        </div>
      ) : rows.length === 0 ? (
        <div className="flex flex-col items-center gap-2 px-6 py-10 text-center">
          <Bell className="size-6 text-muted-foreground/60" aria-hidden />
          <p className="text-sm font-medium">{t("notifications.emptyTitle")}</p>
          <p className="text-xs text-muted-foreground">{t("notifications.emptyDescription")}</p>
        </div>
      ) : (
        <div className="max-h-[min(28rem,70dvh)] overflow-y-auto overscroll-contain">
          <ul className="divide-y">
            {rows.map(({ notification, text }) => {
              const Icon = TYPE_ICONS[notification.type] ?? Bell
              const unread = !notification.read_at
              return (
                <li key={notification.id}>
                  <button
                    type="button"
                    onClick={() => open(notification)}
                    className={cn(
                      "flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none",
                      unread && "bg-primary/[0.04]",
                    )}
                  >
                    <span
                      className={cn(
                        "mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full",
                        ATTENTION_TYPES.has(notification.type)
                          ? "bg-amber-500/10 text-amber-600 dark:text-amber-400"
                          : "bg-primary/10 text-primary",
                      )}
                    >
                      <Icon className="size-4" aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className={cn("line-clamp-1 text-sm", unread ? "font-semibold" : "font-medium text-foreground/80")}>
                        {text.title}
                      </span>
                      {text.body ? (
                        <span className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{text.body}</span>
                      ) : null}
                      <span className="mt-1 block text-[11px] text-muted-foreground/80">
                        {formatDistanceToNow(new Date(notification.updated_at), { addSuffix: true, locale: dateLocale })}
                      </span>
                    </span>
                    {unread ? <span className="mt-2 size-2 shrink-0 rounded-full bg-primary" aria-label={t("notifications.unreadLabel")} /> : null}
                  </button>
                </li>
              )
            })}
          </ul>
          {nextCursor ? (
            <div className="border-t p-2">
              <Button variant="ghost" size="sm" className="w-full text-xs" disabled={loading} onClick={() => loadMore().catch(() => {})}>
                {loading ? <Loader2 className="mr-1.5 size-3.5 animate-spin" /> : null}
                {t("notifications.loadMore")}
              </Button>
            </div>
          ) : null}
        </div>
      )}
    </div>
  )
}
