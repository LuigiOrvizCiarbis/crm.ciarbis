"use client"

import { useEffect, useState } from "react"
import { Laptop, Loader2, LogOut, Monitor, Smartphone } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
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
import { useTranslation } from "@/hooks/useTranslation"
import { getSessions, revokeOtherSessions, revokeSession, type ProfileSession } from "@/lib/api/profile"

function deviceLabel(userAgent: string | null): string {
  if (!userAgent) return "—"
  if (/mobile|android|iphone/i.test(userAgent)) return "Mobile"
  if (/ipad|tablet/i.test(userAgent)) return "Tablet"
  if (/chrome/i.test(userAgent)) return "Chrome"
  if (/firefox/i.test(userAgent)) return "Firefox"
  if (/safari/i.test(userAgent)) return "Safari"
  return "Desktop"
}

function DeviceIcon({ userAgent }: { userAgent: string | null }) {
  if (userAgent && /mobile|android|iphone/i.test(userAgent)) return <Smartphone className="size-4" />
  if (userAgent && /ipad|tablet/i.test(userAgent)) return <Laptop className="size-4" />
  return <Monitor className="size-4" />
}

export function ProfileSessionsBlock() {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [sessions, setSessions] = useState<ProfileSession[]>([])
  const [loading, setLoading] = useState(true)
  const [revokingId, setRevokingId] = useState<number | null>(null)
  const [confirmRevokeAll, setConfirmRevokeAll] = useState(false)
  const [revokingAll, setRevokingAll] = useState(false)

  const load = async () => {
    setLoading(true)
    setSessions(await getSessions())
    setLoading(false)
  }

  useEffect(() => {
    load()
  }, [])

  const handleRevoke = async (id: number) => {
    setRevokingId(id)
    const result = await revokeSession(id)
    setRevokingId(null)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    setSessions((current) => current.filter((s) => s.id !== id))
    addToast({ type: "success", title: t("profile.sessions.revoked") })
  }

  const handleRevokeAll = async () => {
    setRevokingAll(true)
    const result = await revokeOtherSessions()
    setRevokingAll(false)
    setConfirmRevokeAll(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    setSessions((current) => current.filter((s) => s.is_current))
    addToast({ type: "success", title: t("profile.sessions.revokedAll") })
  }

  const otherSessionsCount = sessions.filter((s) => !s.is_current).length

  return (
    <SettingsBlock
      title={t("profile.sessions.title")}
      description={t("profile.sessions.description")}
      icon={LogOut}
      measure="prose"
      action={
        otherSessionsCount > 0 ? (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setConfirmRevokeAll(true)}
            className="bg-transparent"
          >
            {t("profile.sessions.revokeAll")}
          </Button>
        ) : null
      }
    >
      {loading ? (
        <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
      ) : sessions.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("profile.sessions.empty")}</p>
      ) : (
        <div className="space-y-3">
          {sessions.map((session) => (
            <div
              key={session.id}
              className="flex items-center justify-between rounded-lg border border-border p-3"
            >
              <div className="flex items-center gap-3">
                <DeviceIcon userAgent={session.user_agent} />
                <div>
                  <p className="text-sm font-medium">
                    {deviceLabel(session.user_agent)}
                    {session.ip_address ? ` · ${session.ip_address}` : ""}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {session.last_used_at
                      ? t("profile.sessions.lastUsed", { date: new Date(session.last_used_at).toLocaleString() })
                      : t("profile.sessions.neverUsed")}
                    {session.is_current && ` · ${t("profile.sessions.current")}`}
                  </p>
                </div>
              </div>
              {!session.is_current && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => handleRevoke(session.id)}
                  disabled={revokingId === session.id}
                >
                  {revokingId === session.id ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    t("profile.sessions.revoke")
                  )}
                </Button>
              )}
              {session.is_current && <Badge variant="default">{t("profile.sessions.current")}</Badge>}
            </div>
          ))}
        </div>
      )}

      <AlertDialog open={confirmRevokeAll} onOpenChange={setConfirmRevokeAll}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("profile.sessions.revokeAllTitle")}</AlertDialogTitle>
            <AlertDialogDescription>{t("profile.sessions.revokeAllConfirm")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleRevokeAll}
              disabled={revokingAll}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {revokingAll ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
              {t("profile.sessions.revokeAll")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </SettingsBlock>
  )
}
