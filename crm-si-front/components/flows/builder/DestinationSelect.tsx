"use client"

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"
import { DEFAULT_TARGET, screenTarget } from "@/lib/flow-builder/routing"
import type { FlowScreen, Target } from "@/lib/flow-builder/types"

type Translate = (key: string, params?: Record<string, string | number>) => string

/** Nombre visible de un destino desde la pantalla `index`. */
export function destinationLabel(target: Target | undefined, index: number, screens: FlowScreen[], hasAppointment: boolean, t: Translate): string {
  const value = target ?? DEFAULT_TARGET
  if (value === "end" || (value === "following" && index === screens.length - 1)) {
    return hasAppointment ? t("flows.builder.routes.appointment") : t("flows.builder.routes.end")
  }
  if (value === "following") return t("flows.builder.routes.following")
  const position = screens.findIndex((screen) => screenTarget(screen.id) === value)
  return position === -1
    ? t("flows.builder.routes.missing")
    : t("flows.builder.routes.screen", { n: position + 1, title: screens[position].title })
}

interface DestinationSelectProps {
  id: string
  value: Target | undefined
  /** Pantalla desde la que se sale: sólo se ofrecen las posteriores. */
  index: number
  screens: FlowScreen[]
  hasAppointment: boolean
  invalid?: boolean
  ariaLabel?: string
  onChange: (target: Target) => void
}

export function DestinationSelect({ id, value, index, screens, hasAppointment, invalid, ariaLabel, onChange }: DestinationSelectProps) {
  const { t } = useTranslation()
  const current = value ?? DEFAULT_TARGET
  const later = screens.slice(index + 1).map((screen, offset) => ({ screen, position: index + 1 + offset }))
  const options: Target[] = ["following", ...later.map(({ screen }) => screenTarget(screen.id)), "end"]
  // Un destino que dejó de ser válido (pantalla movida antes o borrada) se muestra igual para poder corregirlo.
  if (!options.includes(current)) options.push(current)
  const isLast = index === screens.length - 1

  return (
    <Select value={current} onValueChange={(next) => onChange(next as Target)}>
      <SelectTrigger id={id} className="w-full" aria-invalid={invalid} aria-label={ariaLabel}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options
          // En la última pantalla "siguiente" y "finalizar" son lo mismo: se muestra uno solo.
          .filter((option) => !(isLast && option === "end" && current !== "end"))
          .map((option) => (
            <SelectItem key={option} value={option}>
              {destinationLabel(option, index, screens, hasAppointment, t)}
            </SelectItem>
          ))}
      </SelectContent>
    </Select>
  )
}
