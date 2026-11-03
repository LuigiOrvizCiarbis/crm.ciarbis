"use client"

import { useRef, type KeyboardEvent } from "react"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { TAG_COLORS, findTagColor } from "./tag-colors"

interface TagColorPaletteProps {
  value: string
  onChange: (hex: string) => void
  /** Nombre accesible del grupo, p. ej. "Color de Clientes VIP". */
  label: string
  disabled?: boolean
  /** "sm" entra en una sola fila dentro del selector de etiquetas (w-80). */
  size?: "sm" | "md"
  className?: string
}

/**
 * Grupo de opciones de color con foco itinerante: Tab entra a la muestra
 * elegida, las flechas recorren y Enter/Espacio elige. Un color guardado que no
 * está en la paleta se muestra como "Personalizado" para no perderlo.
 */
export function TagColorPalette({ value, onChange, label, disabled = false, size = "md", className }: TagColorPaletteProps) {
  const { t } = useTranslation()
  const refs = useRef<(HTMLButtonElement | null)[]>([])
  const known = findTagColor(value)
  const options = [
    ...TAG_COLORS.map((color) => ({ hex: color.hex, name: t(`settings.tags.colors.${color.key}`) })),
    ...(known ? [] : [{ hex: value, name: t("settings.tags.custom") }]),
  ]
  const selectedIndex = Math.max(0, options.findIndex((option) => option.hex.toLowerCase() === value.toLowerCase()))

  const moveFocus = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const last = options.length - 1
    const next =
      event.key === "ArrowRight" || event.key === "ArrowDown" ? (index === last ? 0 : index + 1)
      : event.key === "ArrowLeft" || event.key === "ArrowUp" ? (index === 0 ? last : index - 1)
      : event.key === "Home" ? 0
      : event.key === "End" ? last
      : null
    if (next === null) return
    event.preventDefault()
    refs.current[next]?.focus()
  }

  return (
    <div role="radiogroup" aria-label={label} aria-disabled={disabled || undefined} className={cn("flex flex-wrap", size === "sm" ? "gap-1.5" : "gap-2", className)}>
      {options.map((option, index) => {
        const checked = index === selectedIndex
        return (
          <button
            key={option.hex}
            ref={(node) => { refs.current[index] = node }}
            type="button"
            role="radio"
            aria-checked={checked}
            aria-label={option.name}
            title={option.name}
            tabIndex={checked ? 0 : -1}
            disabled={disabled}
            onKeyDown={(event) => moveFocus(event, index)}
            onClick={() => { if (!checked) onChange(option.hex) }}
            className={cn(
              "shrink-0 rounded-full outline-none transition-[box-shadow,transform] motion-safe:duration-150 pointer-coarse:size-10",
              size === "sm" ? "size-6" : "size-7",
              "ring-offset-2 ring-offset-popover focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50",
              // La elegida se marca con un anillo en el color del texto: se distingue sin depender del tono.
              checked ? "ring-2 ring-foreground" : "motion-safe:hover:scale-110",
            )}
            style={{ backgroundColor: option.hex }}
          />
        )
      })}
    </div>
  )
}
