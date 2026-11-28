"use client"

import type { LucideIcon } from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

/** Botón de calificación (👍/👎) para respuestas generadas por la IA. */
export function RatingButton({
  label,
  active,
  disabled = false,
  onClick,
  icon: Icon,
}: {
  label: string
  active: boolean
  disabled?: boolean
  onClick: () => void
  icon: LucideIcon
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      aria-label={label}
      aria-pressed={active}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className={cn(
        "size-7 pointer-coarse:size-10",
        active ? "text-primary" : "text-muted-foreground hover:text-foreground",
      )}
    >
      <Icon className={cn("size-3.5", active && "fill-current/20")} aria-hidden="true" />
    </Button>
  )
}
