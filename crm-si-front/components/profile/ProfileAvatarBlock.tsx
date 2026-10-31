"use client"

import { useRef, useState } from "react"
import { ImageIcon, Loader2, Trash2, Upload } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { deleteAvatar, uploadAvatar } from "@/lib/api/profile"

const MAX_SIZE_BYTES = 2 * 1024 * 1024

export function ProfileAvatarBlock() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [removing, setRemoving] = useState(false)

  const initials = user?.name
    ? user.name.split(" ").map((n) => n[0]).join("").toUpperCase().slice(0, 2)
    : user?.email?.slice(0, 2).toUpperCase() || "U"

  const handleFileChange = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ""
    if (!file) return

    if (!file.type.startsWith("image/")) {
      addToast({ type: "error", title: t("common.error"), description: t("profile.avatar.invalidType") })
      return
    }
    if (file.size > MAX_SIZE_BYTES) {
      addToast({ type: "error", title: t("common.error"), description: t("profile.avatar.tooLarge") })
      return
    }

    const localPreview = URL.createObjectURL(file)
    setPreviewUrl(localPreview)
    setUploading(true)

    const result = await uploadAvatar(file)
    setUploading(false)
    URL.revokeObjectURL(localPreview)
    setPreviewUrl(null)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    updateUser({ avatar_url: result.avatar_url })
    addToast({ type: "success", title: t("profile.avatar.uploaded") })
  }

  const handleRemove = async () => {
    setRemoving(true)
    const result = await deleteAvatar()
    setRemoving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    updateUser({ avatar_url: null })
    addToast({ type: "success", title: t("profile.avatar.removed") })
  }

  return (
    <SettingsBlock
      title={t("profile.avatar.title")}
      description={t("profile.avatar.description")}
      icon={ImageIcon}
      measure="prose"
    >
      <div className="flex items-center gap-4">
        <Avatar className="size-16">
          <AvatarImage src={previewUrl ?? user?.avatar_url ?? undefined} alt={user?.name ?? ""} />
          <AvatarFallback className="bg-primary/10 text-lg font-medium text-primary">{initials}</AvatarFallback>
        </Avatar>

        <div className="flex flex-col gap-2 sm:flex-row">
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={handleFileChange}
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => fileInputRef.current?.click()}
            disabled={uploading || removing}
          >
            {uploading ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Upload className="mr-1.5 size-4" />}
            {t("profile.avatar.upload")}
          </Button>
          {user?.avatar_url && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={handleRemove}
              disabled={uploading || removing}
              className="text-muted-foreground hover:text-destructive"
            >
              {removing ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Trash2 className="mr-1.5 size-4" />}
              {t("profile.avatar.remove")}
            </Button>
          )}
        </div>
      </div>
    </SettingsBlock>
  )
}
