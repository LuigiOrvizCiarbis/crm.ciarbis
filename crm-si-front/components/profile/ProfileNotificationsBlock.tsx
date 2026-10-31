"use client"

import { useState, useSyncExternalStore } from "react"
import { Bell } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import {
  NOTIFICATION_TYPES,
  updateNotificationPreferences,
  type NotificationPreferences,
  type NotificationType,
} from "@/lib/api/notifications"
import { canUseBrowserNotifications } from "@/lib/notificationPresentation"
import { useAuthStore } from "@/store/useAuthStore"

/** Debe coincidir con User::notificationPreferences() en el backend. */
const DEFAULT_NOTIFICATION_PREFERENCES: NotificationPreferences = {
  types: Object.fromEntries(NOTIFICATION_TYPES.map((type) => [type, true])) as Record<NotificationType, boolean>,
  sound: true,
  browser: false,
}

type BrowserPermission = NotificationPermission | "unsupported"

const noopSubscribe = () => () => {}
const readPermission = (): BrowserPermission => (canUseBrowserNotifications() ? Notification.permission : "unsupported")

export function ProfileNotificationsBlock() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)
  const preferences = user?.preferences?.notifications ?? DEFAULT_NOTIFICATION_PREFERENCES
  const [saving, setSaving] = useState(false)
  const currentPermission = useSyncExternalStore(noopSubscribe, readPermission, () => "default" as BrowserPermission)
  // El permiso no emite eventos confiables: guardamos el resultado del prompt.
  const [requestedPermission, setRequestedPermission] = useState<BrowserPermission | null>(null)
  const permission = requestedPermission ?? currentPermission

  const save = async (input: Partial<NotificationPreferences>) => {
    setSaving(true)
    try {
      const next = await updateNotificationPreferences(input)
      updateUser({ preferences: next })
    } catch (error) {
      addToast({ type: "error", title: t("common.error"), description: error instanceof Error ? error.message : undefined })
    } finally {
      setSaving(false)
    }
  }

  const toggleBrowser = async (enabled: boolean) => {
    if (enabled && permission !== "granted") {
      if (permission === "unsupported" || permission === "denied") return
      const result = await Notification.requestPermission()
      setRequestedPermission(result)
      if (result !== "granted") return
    }
    await save({ browser: enabled })
  }

  return (
    <SettingsBlock
      title={t("notifications.preferences.title")}
      description={t("notifications.preferences.description")}
      icon={Bell}
      measure="prose"
    >
      <div className="space-y-6">
        <div className="space-y-4">
          <div className="flex items-start justify-between gap-4">
            <div className="space-y-1">
              <Label htmlFor="notifications-browser">{t("notifications.preferences.browser")}</Label>
              <p className="text-xs text-muted-foreground">
                {permission === "denied"
                  ? t("notifications.preferences.browserDenied")
                  : permission === "unsupported"
                    ? t("notifications.preferences.browserUnsupported")
                    : t("notifications.preferences.browserHint")}
              </p>
            </div>
            <Switch
              id="notifications-browser"
              checked={preferences.browser && permission === "granted"}
              disabled={saving || permission === "denied" || permission === "unsupported"}
              onCheckedChange={(checked) => void toggleBrowser(checked)}
            />
          </div>

          <div className="flex items-start justify-between gap-4">
            <div className="space-y-1">
              <Label htmlFor="notifications-sound">{t("notifications.preferences.sound")}</Label>
              <p className="text-xs text-muted-foreground">{t("notifications.preferences.soundHint")}</p>
            </div>
            <Switch
              id="notifications-sound"
              checked={preferences.sound}
              disabled={saving}
              onCheckedChange={(checked) => void save({ sound: checked })}
            />
          </div>
        </div>

        <div className="space-y-3">
          <p className="text-sm font-medium">{t("notifications.preferences.typesTitle")}</p>
          {NOTIFICATION_TYPES.map((type) => {
            const id = `notification-type-${type.replace(".", "-")}`
            return (
              <div key={type} className="flex items-center justify-between gap-4">
                <Label htmlFor={id} className="cursor-pointer font-normal">
                  {t(`notifications.types.${type.replace(".", "_")}.label`)}
                </Label>
                <Switch
                  id={id}
                  checked={preferences.types[type] ?? true}
                  disabled={saving}
                  onCheckedChange={(checked) => void save({ types: { [type]: checked } as Record<NotificationType, boolean> })}
                />
              </div>
            )
          })}
        </div>
      </div>
    </SettingsBlock>
  )
}
