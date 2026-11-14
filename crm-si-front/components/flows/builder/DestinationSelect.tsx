"use client"

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"
import { DEFAULT_TARGET, canRouteTo, resolveTarget, screenTarget } from "@/lib/flow-builder/routing"
import type { FlowScreen, Target } from "@/lib/flow-builder/types"

type Translate = (key: string, params?: Record<string, string | number>) => string

/** Nombre visible de un destino desde la pantalla `index`. */
export function destinationLabel(target: Target | undefined, index: number, screens: FlowScreen[], hasAppointment: boolean, t: Translate): string {
  const resolved = resolveTarget(target ?? DEFAULT_TARGET, index, screens)
  if (resolved === null) return t("flows.builder.routes.missing")
  if (resolved === "end") return hasAppointment ? t("flows.builder.routes.appointment") : t("flows.builder.routes.end")
  return t("flows.builder.routes.screen", { n: resolved + 1, title: screens[resolved].title })
}

interface DestinationSelectProps {
  id: string
  value: Target | undefined
  /** Pantalla desde la que se sale: sólo se ofrecen destinos que no vuelven atrás. */
  index: number
  screens: FlowScreen[]
  hasAppointment: boolean
  invalid?: boolean
  ariaLabel?: string
  onChange: (target: Target) => void
}

/** Alternativa por teclado a conectar flechas en el diagrama. */
export function DestinationSelect({ id, value, index, screens, hasAppointment, invalid, ariaLabel, onChange }: DestinationSelectProps) {
  const { t } = useTranslation()
  const current = value ?? DEFAULT_TARGET
  const options: Target[] = [
    ...screens.flatMap((screen, position) => (canRouteTo(screens, index, position) ? [screenTarget(screen.id)] : [])),
    "end",
  ]
  // Un destino que dejó de ser válido se muestra igual para poder corregirlo.
  if (!options.includes(current)) options.unshift(current)

  return (
    <Select value={current} onValueChange={(next) => onChange(next as Target)}>
      <SelectTrigger id={id} className="w-full" aria-invalid={invalid} aria-label={ariaLabel}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option} value={option}>
            {destinationLabel(option, index, screens, hasAppointment, t)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
