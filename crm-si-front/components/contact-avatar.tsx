import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { cn } from "@/lib/utils"
import { Avatar as DiceBearAvatar, Style } from "@dicebear/core"
import shapes from "@dicebear/styles/shapes.json"
import { useMemo } from "react"

const shapesStyle = new Style(shapes)

function getInitials(name: string): string {
  const initials = name
    .trim()
    .split(/\s+/)
    .map((part) => part[0])
    .filter(Boolean)
    .join("")
    .slice(0, 2)
    .toUpperCase()

  return initials || "?"
}

function getGeneratedAvatar(contactId: string | number): string {
  return new DiceBearAvatar(shapesStyle, {
    seed: `contact:${contactId}`,
    size: 128,
    backgroundColor: "#f1f4dc",
    idRandomization: false,
  }).toDataUri()
}

interface ContactAvatarProps {
  contactId?: string | number | null
  name?: string | null
  imageUrl?: string | null
  className?: string
  fallbackClassName?: string
}

/**
 * Shared contact identity avatar. Generated artwork is deterministic by the
 * internal contact id, so changing a contact name does not change its avatar.
 * `imageUrl` is intentionally supported for a future manual/channel photo.
 */
export function ContactAvatar({
  contactId,
  name = "",
  imageUrl,
  className,
  fallbackClassName,
}: ContactAvatarProps) {
  const generatedAvatar = useMemo(
    () => (contactId === null || contactId === undefined ? null : getGeneratedAvatar(contactId)),
    [contactId],
  )
  const src = imageUrl || generatedAvatar

  return (
    <Avatar className={cn("bg-muted", className)}>
      {src ? <AvatarImage src={src} alt="" /> : null}
      <AvatarFallback className={cn("text-xs font-medium", fallbackClassName)}>
        {getInitials(name ?? "")}
      </AvatarFallback>
    </Avatar>
  )
}
