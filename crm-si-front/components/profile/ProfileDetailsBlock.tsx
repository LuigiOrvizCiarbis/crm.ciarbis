"use client"

import { useMemo, useState } from "react"
import Link from "next/link"
import { Loader2, User } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { updateProfile } from "@/lib/api/profile"

export function ProfileDetailsBlock() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)

  const [name, setName] = useState(user?.name ?? "")
  const [phone, setPhone] = useState(user?.phone ?? "")
  const [jobTitle, setJobTitle] = useState(user?.job_title ?? "")
  const [saving, setSaving] = useState(false)

  const hasChanges = useMemo(() => {
    return (
      name !== (user?.name ?? "") ||
      phone !== (user?.phone ?? "") ||
      jobTitle !== (user?.job_title ?? "")
    )
  }, [name, phone, jobTitle, user?.name, user?.phone, user?.job_title])

  const save = async () => {
    if (!name.trim()) {
      addToast({ type: "error", title: t("common.error"), description: t("profile.details.nameRequired") })
      return
    }

    setSaving(true)
    const result = await updateProfile({
      name: name.trim(),
      phone: phone.trim() || null,
      job_title: jobTitle.trim() || null,
    })
    setSaving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    if (result.data) updateUser(result.data)
    addToast({ type: "success", title: t("profile.details.saved") })
  }

  return (
    <SettingsBlock
      title={t("profile.details.title")}
      description={t("profile.details.description")}
      icon={User}
      measure="prose"
      action={
        <Button size="sm" onClick={save} disabled={!hasChanges || saving}>
          {saving ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
          {saving ? t("common.saving") : t("common.save")}
        </Button>
      }
    >
      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="profile-name">{t("profile.details.name")}</Label>
          <Input id="profile-name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} />
        </div>

        <div className="space-y-2">
          <Label htmlFor="profile-email">{t("profile.details.email")}</Label>
          <Input id="profile-email" type="email" value={user?.email ?? ""} disabled />
          <p className="text-xs text-muted-foreground">
            {t("profile.details.emailHint")}{" "}
            <Link href="/verify-email" className="underline underline-offset-2 hover:text-foreground">
              {t("profile.details.emailChangeLink")}
            </Link>
          </p>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="profile-phone">{t("profile.details.phone")}</Label>
            <Input
              id="profile-phone"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="+54 9 11 1234-5678"
              maxLength={30}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="profile-job-title">{t("profile.details.jobTitle")}</Label>
            <Input
              id="profile-job-title"
              value={jobTitle}
              onChange={(e) => setJobTitle(e.target.value)}
              maxLength={100}
            />
          </div>
        </div>
      </div>
    </SettingsBlock>
  )
}
