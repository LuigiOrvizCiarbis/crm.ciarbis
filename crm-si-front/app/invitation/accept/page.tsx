"use client"

import { useEffect, useState } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { Suspense } from "react"
import { Loader2, Users, AlertCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { useAuthStore } from "@/store/useAuthStore"
import { setWorkspaceId } from "@/lib/api/auth-token"
import { getInvitationByToken, acceptInvitation, type InvitationDetails } from "@/lib/api/invitations"
import { useTranslation } from "@/hooks/useTranslation"
import { firstAccessibleSection } from "@/lib/section-access"

function AcceptInvitationContent() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const token = searchParams.get("token")
  const { t } = useTranslation()
  const { isAuthenticated, user, setAuth, _hasHydrated } = useAuthStore()

  const [invitation, setInvitation] = useState<InvitationDetails | null>(null)
  const [error, setError] = useState("")
  const [loading, setLoading] = useState(true)
  const [accepting, setAccepting] = useState(false)

  useEffect(() => {
    if (!_hasHydrated) return

    if (!token) {
      setError(t("team.invitationInvalid"))
      setLoading(false)
      return
    }

    getInvitationByToken(token).then(({ data, error: err }) => {
      if (err) {
        setError(err)
      } else if (data) {
        setInvitation(data)
      }
      setLoading(false)
    })
  }, [token, _hasHydrated])

  const handleAccept = async () => {
    if (!token) return
    setAccepting(true)

    const { token: newToken, user: newUser, workspace, role, permissions, error: err } = await acceptInvitation(token)
    if (err) {
      setError(err)
      setAccepting(false)
      return
    }

    if (workspace?.id) {
      setWorkspaceId(workspace.id)
    }

    if (newToken && newUser) {
      setAuth(newUser, newToken, false, !!newUser.email_verified_at, role ?? null, permissions ?? [])
      router.push(firstAccessibleSection(permissions ?? [], role ?? null)?.href ?? "/sin-acceso")
    }
  }

  if (!_hasHydrated || loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <Loader2 className="w-8 h-8 animate-spin text-primary" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-linear-to-br from-background via-background to-muted/30">
        <Card className="w-full max-w-md mx-4">
          <CardContent className="pt-8 pb-6 text-center space-y-4">
            <AlertCircle className="w-12 h-12 text-destructive mx-auto" />
            <p className="text-muted-foreground">{error}</p>
            <Link href="/login">
              <Button variant="outline">{t("auth.submit")}</Button>
            </Link>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (!invitation) return null

  const emailMatch = isAuthenticated && user?.email === invitation.email

  return (
    <div className="min-h-screen flex items-center justify-center bg-linear-to-br from-background via-background to-muted/30">
      <div className="absolute inset-0 bg-grid-pattern opacity-[0.02] pointer-events-none" />
      <div className="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
        <div className="absolute -top-40 -right-40 w-80 h-80 bg-primary/10 rounded-full blur-3xl" />
        <div className="absolute -bottom-40 -left-40 w-80 h-80 bg-secondary/10 rounded-full blur-3xl" />
      </div>

      <Card className="relative z-10 w-full max-w-md mx-4 border-border/50 shadow-xl">
        <CardHeader className="text-center space-y-4">
          <div className="mx-auto w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
            <Users className="w-6 h-6 text-primary" />
          </div>
          <div>
            <CardTitle className="text-2xl font-bold">{t("team.acceptTitle")}</CardTitle>
            <p className="text-muted-foreground mt-2">
              {t("team.acceptDesc")}
            </p>
          </div>
        </CardHeader>

        <CardContent className="space-y-6">
          {/* Invitation details */}
          <div className="bg-muted/50 rounded-xl p-4 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">{t("team.workspace")}</span>
              <span className="font-semibold">{invitation.tenant_name}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">{t("team.invitedBy")}</span>
              <span className="font-medium">{invitation.inviter_name}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">{t("team.role")}</span>
              <Badge variant="outline">{invitation.role_name}</Badge>
            </div>
          </div>

          {/* Actions based on auth state */}
          {isAuthenticated && emailMatch ? (
            <Button className="w-full" onClick={handleAccept} disabled={accepting}>
              {accepting ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin mr-2" />
                  {t("common.loading")}
                </>
              ) : (
                t("team.acceptButton")
              )}
            </Button>
          ) : isAuthenticated && !emailMatch ? (
            <div className="text-center space-y-3">
              <p className="text-sm text-muted-foreground">
                {t("team.emailMismatch")} <strong>{invitation.email}</strong>
              </p>
              <p className="text-xs text-muted-foreground">
                {t("team.emailMismatchHint")}
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              <Link href={`/register?invitation_token=${token}&email=${encodeURIComponent(invitation.email)}`} className="block">
                <Button className="w-full">{t("team.createAccount")}</Button>
              </Link>
              <Link href={`/login?redirect=${encodeURIComponent(`/invitation/accept?token=${token}`)}`} className="block">
                <Button variant="outline" className="w-full">{t("team.haveAccount")}</Button>
              </Link>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

export default function AcceptInvitationPage() {
  return (
    <Suspense fallback={
      <div className="min-h-screen flex items-center justify-center bg-background">
        <Loader2 className="w-8 h-8 animate-spin text-primary" />
      </div>
    }>
      <AcceptInvitationContent />
    </Suspense>
  )
}
