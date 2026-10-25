"use client"

import { useEffect, useState, type FormEvent } from "react"
import { Loader2 } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import { Button } from "@/components/ui/button"
import { useAuthStore } from "@/store/useAuthStore"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { subscribeWhatsAppNotifications, revokeWhatsAppNotifications } from "@/lib/api/profile"

export function ProfileWhatsAppNotificationsBlock() {
  const { t } = useTranslation()
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)
  const { addToast } = useToast()
  const savedPhone = user?.whatsapp_notification?.phone ?? ""
  const subscribed = Boolean(user?.whatsapp_notification?.opted_in_at && savedPhone)
  const [phone, setPhone] = useState(savedPhone)
  const [consent, setConsent] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => { setPhone(savedPhone) }, [savedPhone])

  const changingNumber = phone.trim() !== savedPhone.trim()
  const needsConsent = !subscribed || changingNumber

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    if (!consent) {
      setError(t("profile.whatsappNotifications.consentRequired"))
      return
    }
    const digits = phone.replace(/\D/g, "")
    if (digits.length < 8 || digits.length > 15) {
      setError(t("profile.whatsappNotifications.invalidPhone"))
      return
    }

    setBusy(true)
    setError(null)
    try {
      const result = await subscribeWhatsAppNotifications(phone.trim(), consent)
      if (result.error) {
        setError(result.error)
        return
      }
      if (result.data) updateUser(result.data)
      setConsent(false)
      addToast({ type: "success", title: t("profile.whatsappNotifications.saved") })
    } catch {
      setError(t("profile.whatsappNotifications.networkError"))
    } finally {
      setBusy(false)
    }
  }

  async function revoke() {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      const result = await revokeWhatsAppNotifications()
      if (result.error) {
        setError(result.error)
        return
      }
      if (result.data) updateUser(result.data)
      setPhone("")
      setConsent(false)
      addToast({ type: "success", title: t("profile.whatsappNotifications.disabled") })
    } catch {
      setError(t("profile.whatsappNotifications.networkError"))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader><CardTitle>{t("profile.whatsappNotifications.title")}</CardTitle></CardHeader>
      <CardContent>
        <form noValidate onSubmit={save} className="space-y-4">
          <p className="text-sm text-muted-foreground">{t("profile.whatsappNotifications.description")}</p>
          {subscribed && <p className="text-sm text-foreground">{t("profile.whatsappNotifications.active")}</p>}
          <div className="space-y-2">
            <Label htmlFor="profile-whatsapp-alert-phone">{t("profile.whatsappNotifications.phone")}</Label>
            <Input
              id="profile-whatsapp-alert-phone"
              type="tel"
              autoComplete="tel"
              inputMode="tel"
              value={phone}
              onChange={(event) => { setPhone(event.target.value); setConsent(false); setError(null) }}
              placeholder="+54 9 11 1234-5678"
              maxLength={40}
              aria-invalid={Boolean(error)}
              aria-describedby={error ? "profile-whatsapp-alert-error" : "profile-whatsapp-alert-hint"}
            />
            <p id="profile-whatsapp-alert-hint" className="text-xs text-muted-foreground">{t("profile.whatsappNotifications.phoneHint")}</p>
          </div>
          {needsConsent && (
            <div className="flex items-start gap-2">
              <Checkbox id="profile-whatsapp-alert-consent" checked={consent} onCheckedChange={(checked) => { setConsent(checked === true); setError(null) }} aria-required="true" />
              <Label htmlFor="profile-whatsapp-alert-consent" className="text-sm leading-5">{t("profile.whatsappNotifications.consent")}</Label>
            </div>
          )}
          {error && <p id="profile-whatsapp-alert-error" role="alert" className="text-sm text-destructive">{error}</p>}
          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={busy || !needsConsent || !consent || !phone.trim()}>
              {busy && <Loader2 className="mr-1.5 size-4 animate-spin" />}
              {t("profile.whatsappNotifications.save")}
            </Button>
            {subscribed && <Button type="button" variant="outline" onClick={revoke} disabled={busy}>{t("profile.whatsappNotifications.disable")}</Button>}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
