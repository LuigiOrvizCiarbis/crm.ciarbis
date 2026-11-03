"use client"

import { X } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import type { Tag } from "@/lib/api/tags"
import { cn } from "@/lib/utils"
import { useTagColorsStore } from "@/store/useTagColorsStore"

interface TagChipsProps {
  tags?: Tag[]
  maxVisible?: number
  removable?: boolean
  onRemove?: (tag: Tag) => void
  className?: string
  emptyLabel?: string
}

export function TagChips({
  tags = [],
  maxVisible,
  removable = false,
  onRemove,
  className,
  emptyLabel,
}: TagChipsProps) {
  const colorOverrides = useTagColorsStore((state) => state.colors)
  const visibleTags = typeof maxVisible === "number" ? tags.slice(0, maxVisible) : tags
  const hiddenCount = typeof maxVisible === "number" ? Math.max(tags.length - maxVisible, 0) : 0

  if (tags.length === 0) {
    return emptyLabel ? <span className="text-xs text-muted-foreground">{emptyLabel}</span> : null
  }

  return (
    <div className={cn("flex flex-wrap items-center gap-1.5", className)}>
      {visibleTags.map((tag) => {
        const color = colorOverrides[tag.id] ?? tag.color
        return (
          <Badge
            key={tag.id}
            variant="outline"
            className="h-6 max-w-[10rem] gap-1 rounded-md border px-2 text-[11px] font-medium"
            // El color de la etiqueta es dato del usuario y puede ser cualquier
            // hex: va en borde y tinte, nunca como color de texto.
            style={{
              borderColor: color,
              backgroundColor: `${color}1A`,
            }}
            title={tag.name}
          >
            <span className="truncate">{tag.name}</span>
            {removable && (
              <button
                type="button"
                className="rounded-sm outline-none transition-colors hover:bg-foreground/10 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                onClick={(event) => {
                  event.stopPropagation()
                  onRemove?.(tag)
                }}
                aria-label={`Quitar ${tag.name}`}
              >
                <X className="h-3 w-3" aria-hidden="true" />
              </button>
            )}
          </Badge>
        )
      })}
      {hiddenCount > 0 && (
        <Badge variant="outline" className="h-6 rounded-md px-2 text-[11px] text-muted-foreground">
          +{hiddenCount}
        </Badge>
      )}
    </div>
  )
}
