"use client"

import type { ReactNode } from "react"

import { Label } from "@/components/ui/label"
import { charCount } from "@/lib/flow-builder/validate"
import { cn } from "@/lib/utils"

interface BuilderFieldProps {
  id: string
  label: string
  /** Texto actual, para el contador `n/límite`. */
  value?: string | null
  max?: number
  errors?: string[]
  hint?: string
  children: ReactNode
}

/** Campo del constructor: etiqueta, contador de caracteres y errores (del cliente o del servidor). */
export function BuilderField({ id, label, value, max, errors, hint, children }: BuilderFieldProps) {
  const count = charCount(value)
  const showCounter = max !== undefined && Number.isFinite(max)

  return (
    <div className="space-y-1.5">
      <div className="flex items-baseline justify-between gap-2">
        <Label htmlFor={id} className="text-xs font-medium text-muted-foreground">
          {label}
        </Label>
        {showCounter ? (
          <span
            className={cn(
              "font-mono text-[11px] tabular-nums",
              count > max ? "text-destructive" : "text-muted-foreground/70",
            )}
            aria-live="polite"
          >
            {count}/{max}
          </span>
        ) : null}
      </div>
      {children}
      {hint && !errors?.length ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      <FieldErrorText errors={errors} id={`${id}-error`} />
    </div>
  )
}

export function FieldErrorText({ errors, id }: { errors?: string[]; id?: string }) {
  if (!errors?.length) return null
  return (
    <p id={id} className="text-xs text-destructive" role="alert">
      {[...new Set(errors)].join(" ")}
    </p>
  )
}
