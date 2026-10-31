"use client"

import { useState } from "react"
import { KeyRound, Loader2 } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { updatePassword } from "@/lib/api/profile"

export function ProfilePasswordBlock() {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [currentPassword, setCurrentPassword] = useState("")
  const [password, setPassword] = useState("")
  const [passwordConfirmation, setPasswordConfirmation] = useState("")
  const [saving, setSaving] = useState(false)

  const canSubmit =
    currentPassword.length > 0 && password.length >= 8 && password === passwordConfirmation

  const reset = () => {
    setCurrentPassword("")
    setPassword("")
    setPasswordConfirmation("")
  }

  const save = async () => {
    if (password !== passwordConfirmation) {
      addToast({ type: "error", title: t("common.error"), description: t("profile.password.mismatch") })
      return
    }

    setSaving(true)
    const result = await updatePassword({
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    })
    setSaving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    reset()
    addToast({ type: "success", title: t("profile.password.saved"), description: t("profile.password.savedDesc") })
  }

  return (
    <SettingsBlock
      title={t("profile.password.title")}
      description={t("profile.password.description")}
      icon={KeyRound}
      measure="prose"
      action={
        <Button size="sm" onClick={save} disabled={!canSubmit || saving}>
          {saving ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
          {saving ? t("common.saving") : t("profile.password.change")}
        </Button>
      }
    >
      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="profile-current-password">{t("profile.password.current")}</Label>
          <Input
            id="profile-current-password"
            type="password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="profile-new-password">{t("profile.password.new")}</Label>
            <Input
              id="profile-new-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="new-password"
              minLength={8}
              required
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="profile-new-password-confirmation">{t("profile.password.confirm")}</Label>
            <Input
              id="profile-new-password-confirmation"
              type="password"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              autoComplete="new-password"
              minLength={8}
              required
            />
          </div>
        </div>
        <p className="text-xs text-muted-foreground">{t("profile.password.sessionsWarning")}</p>
      </div>
    </SettingsBlock>
  )
}
