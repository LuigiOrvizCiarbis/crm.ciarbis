"use client"

import { useRef, useState } from "react"
import { Loader2, Trash2, Upload } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import {
  deleteContactAvatar,
  uploadContactAvatar,
  type ContactAvatar,
  type ContactAvatarSource,
} from "@/lib/api/contacts"

// Mismo límite que valida el backend (ContactAvatarController::store).
const MAX_SIZE_BYTES = 2 * 1024 * 1024
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp"]

const CHANNEL_SOURCE_KEYS: Partial<Record<ContactAvatarSource, string>> = {
  facebook: "contactAvatar.fromFacebook",
  instagram: "contactAvatar.fromInstagram",
}

interface ContactAvatarActionsProps {
  contactId: number
  avatarUrl: string | null | undefined
  avatarSource: ContactAvatarSource | null | undefined
  onChange: (avatar: ContactAvatar) => void
  className?: string
}

/**
 * Subir o quitar la foto manual de un contacto. Para WhatsApp es la única vía:
 * la Cloud API no expone la foto del cliente. Quitar sólo aplica a la foto
 * manual; la de Messenger/Instagram la administra el canal.
 */
export function ContactAvatarActions({
  contactId,
  avatarUrl,
  avatarSource,
  onChange,
  className,
}: ContactAvatarActionsProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canUpdate = usePermission("contacts.update")
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [removing, setRemoving] = useState(false)

  const channelSourceKey = avatarSource ? CHANNEL_SOURCE_KEYS[avatarSource] : undefined
  const busy = uploading || removing

  const handleFileChange = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ""
    if (!file) return

    if (!ACCEPTED_TYPES.includes(file.type)) {
      addToast({ type: "error", title: t("contactAvatar.uploadError"), description: t("contactAvatar.invalidType") })
      return
    }
    if (file.size > MAX_SIZE_BYTES) {
      addToast({ type: "error", title: t("contactAvatar.uploadError"), description: t("contactAvatar.tooLarge") })
      return
    }

    setUploading(true)
    try {
      onChange(await uploadContactAvatar(contactId, file))
      addToast({ type: "success", title: t("contactAvatar.uploaded") })
    } catch (error) {
      addToast({
        type: "error",
        title: t("contactAvatar.uploadError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setUploading(false)
    }
  }

  const handleRemove = async () => {
    setRemoving(true)
    try {
      onChange(await deleteContactAvatar(contactId))
      addToast({ type: "success", title: t("contactAvatar.removed") })
    } catch (error) {
      addToast({
        type: "error",
        title: t("contactAvatar.removeError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setRemoving(false)
    }
  }

  if (!canUpdate) {
    return channelSourceKey ? (
      <p className={cn("text-xs text-muted-foreground", className)}>{t(channelSourceKey)}</p>
    ) : null
  }

  return (
    <div className={cn("flex flex-col gap-1.5", className)}>
      <div className="flex flex-wrap items-center gap-2">
        <input
          ref={fileInputRef}
          type="file"
          accept={ACCEPTED_TYPES.join(",")}
          className="hidden"
          onChange={handleFileChange}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => fileInputRef.current?.click()}
          disabled={busy}
        >
          {uploading ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Upload className="mr-1.5 size-4" />}
          {avatarUrl ? t("contactAvatar.change") : t("contactAvatar.upload")}
        </Button>
        {avatarSource === "manual" && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={handleRemove}
            disabled={busy}
            className="text-muted-foreground hover:text-destructive"
          >
            {removing ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : <Trash2 className="mr-1.5 size-4" />}
            {t("contactAvatar.remove")}
          </Button>
        )}
      </div>
      {channelSourceKey && <p className="text-xs text-muted-foreground">{t(channelSourceKey)}</p>}
    </div>
  )
}
