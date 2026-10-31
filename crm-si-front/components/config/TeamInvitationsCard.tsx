"use client"

import { useState, useEffect } from "react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { UserPlus, Loader2, X, Mail, Clock } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { getInvitations, createInvitation, deleteInvitation, type Invitation } from "@/lib/api/invitations"
import { getRoles, type Role } from "@/lib/api/roles"

export function TeamInvitationsCard() {
  const { addToast } = useToast()
  const { t } = useTranslation()

  const [invitations, setInvitations] = useState<Invitation[]>([])
  const [roles, setRoles] = useState<Role[]>([])
  const [email, setEmail] = useState("")
  const [roleName, setRoleName] = useState<string>("Admin")
  const [sending, setSending] = useState(false)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    loadInvitations()
    loadRoles()
  }, [])

  const loadInvitations = async () => {
    setLoading(true)
    const data = await getInvitations()
    setInvitations(data)
    setLoading(false)
  }

  const loadRoles = async () => {
    const data = await getRoles()
    setRoles(data)
    const fallback = data.find((r) => r.name === "Admin") ?? data[0]
    if (fallback) {
      setRoleName(fallback.name)
    }
  }

  const handleSend = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!email.trim()) return

    setSending(true)
    const { error } = await createInvitation(email.trim(), roleName)
    setSending(false)

    if (error) {
      addToast({ title: t("team.invitationError"), description: error, type: "error" })
      return
    }

    addToast({
      type: "success",
      title: t("team.invitationSent"),
      description: `${t("team.invitationSentDesc")} ${email}`,
    })
    setEmail("")
    loadInvitations()
  }

  const handleRevoke = async (id: number) => {
    const { error } = await deleteInvitation(id)
    if (error) {
      addToast({ title: t("common.error"), description: error, type: "error" })
      return
    }
    addToast({ type: "success", title: t("team.invitationRevoked") })
    loadInvitations()
  }

  const pendingInvitations = invitations.filter((i) => !i.accepted_at && new Date(i.expires_at) > new Date())
  const acceptedInvitations = invitations.filter((i) => i.accepted_at)

  const getInitial = (email: string) => email.trim().charAt(0).toUpperCase() || "?"

  return (
    <SettingsBlock
      title={t("team.inviteTitle")}
      icon={UserPlus}
      measure="prose"
    >
      <div className="space-y-7">
        {/* Invite form */}
        <form onSubmit={handleSend} className="space-y-2">
          <Input
            type="email"
            placeholder={t("team.emailPlaceholder")}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            disabled={sending}
            className="w-full"
          />
          <div className="flex gap-2">
            <Select value={roleName} onValueChange={setRoleName}>
              <SelectTrigger className="flex-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {roles.map((r) => (
                  <SelectItem key={r.id} value={r.name}>
                    {r.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              type="submit"
              disabled={sending || roles.length === 0}
              className="shrink-0"
            >
              {sending ? <Loader2 className="size-4 animate-spin" /> : t("team.sendInvitation")}
            </Button>
          </div>
        </form>

        {/* Pending invitations */}
        {loading ? (
          <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
        ) : pendingInvitations.length > 0 ? (
          <div className="space-y-2">
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
              {t("team.pendingInvitations")}
            </p>
            <div className="divide-y divide-border border-y border-border">
              {pendingInvitations.map((inv) => (
                <div
                  key={inv.id}
                  className="group flex items-center gap-3 py-3 transition-colors hover:bg-muted/40"
                >
                  <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                    {getInitial(inv.email)}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{inv.email}</p>
                    <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                      <Badge variant="outline" className="text-xs font-normal">
                        {inv.role_name}
                      </Badge>
                      <span className="inline-flex items-center gap-1 whitespace-nowrap">
                        <Clock className="size-3" />
                        {t("team.expires")} {new Date(inv.expires_at).toLocaleDateString()}
                      </span>
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                    onClick={() => handleRevoke(inv.id)}
                    aria-label={t("team.invitationRevoked")}
                  >
                    <X className="size-4" />
                  </Button>
                </div>
              ))}
            </div>
          </div>
        ) : (
          <p className="py-2 text-sm text-muted-foreground">{t("team.noInvitations")}</p>
        )}

        {/* Accepted invitations */}
        {acceptedInvitations.length > 0 && (
          <div className="space-y-2">
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
              {t("team.accepted")}
            </p>
            <div className="divide-y divide-border border-y border-border">
              {acceptedInvitations.map((inv) => (
                <div key={inv.id} className="flex items-center gap-3 py-3">
                  <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-muted-foreground">
                    {getInitial(inv.email)}
                  </div>
                  <p className="min-w-0 flex-1 truncate text-sm">{inv.email}</p>
                  <Badge variant="secondary" className="shrink-0 text-xs">
                    {t("team.accepted")}
                  </Badge>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </SettingsBlock>
  )
}
