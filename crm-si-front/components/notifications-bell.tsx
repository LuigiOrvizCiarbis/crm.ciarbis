"use client"

import { useState } from "react"
import { Bell } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { NotificationsPanel } from "@/components/notifications/NotificationsPanel"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { useNotificationsStore } from "@/store/useNotificationsStore"

interface NotificationsBellProps {
  side?: "top" | "right" | "bottom" | "left"
  align?: "start" | "center" | "end"
  className?: string
}

export function formatNotificationCount(count: number) {
  return count > 99 ? "99+" : String(count)
}

export function NotificationsBell({ side = "right", align = "start", className }: NotificationsBellProps) {
  const [open, setOpen] = useState(false)
  const { t } = useTranslation()
  const unreadCount = useNotificationsStore((state) => state.unreadCount)

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className={cn("relative p-2 hover:bg-sidebar-accent", className)}
          aria-label={unreadCount > 0 ? t("notifications.bellLabelUnread", { count: unreadCount }) : t("notifications.title")}
        >
          <Bell className="h-4 w-4" />
          {unreadCount > 0 ? (
            <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-white tabular-nums">
              {formatNotificationCount(unreadCount)}
            </span>
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent side={side} align={align} sideOffset={8} className="w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden p-0">
        <NotificationsPanel onNavigate={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  )
}
