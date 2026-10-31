"use client"

import { useEffect, useState } from "react"

import { Input } from "@/components/ui/input"
import { cn } from "@/lib/utils"
import {
  currencySymbol,
  formatCurrency,
  parseCurrencyInput,
  resolveCurrency,
} from "@/lib/currency"

interface CurrencyInputProps {
  id?: string
  value: unknown
  /** Divisa del campo (options.currency). Cae en el default si no es válida. */
  currency?: unknown
  onChange: (next: number | null) => void
  disabled?: boolean
  className?: string
}

/**
 * Input de importe: formateado mientras se lo mira, crudo mientras se lo edita.
 *
 * Se usa type="text" y no type="number" a propósito. Un input numérico no
 * admite separadores de miles, así que no habría forma de mostrar
 * "$ 1.250.000,50" sin que el navegador considere el valor inválido; además su
 * rueda del mouse cambia el importe sin querer al hacer scroll en un formulario
 * largo.
 */
export function CurrencyInput({ id, value, currency, onChange, disabled, className }: CurrencyInputProps) {
  const code = resolveCurrency(currency)
  const [focused, setFocused] = useState(false)
  const [draft, setDraft] = useState("")

  // Mientras el campo está enfocado, el estado local manda: reformatear al
  // vuelo movería el cursor de lugar en medio de la escritura.
  useEffect(() => {
    if (!focused) setDraft(value === null || value === undefined ? "" : String(value))
  }, [value, focused])

  const display = focused ? draft : formatCurrency(value, code)
  const isEmpty = value === null || value === undefined || value === ""

  return (
    <div className="relative">
      <span
        className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm tabular-nums"
        aria-hidden="true"
      >
        {currencySymbol(code)}
      </span>
      <Input
        id={id}
        type="text"
        inputMode="decimal"
        // El símbolo ya se dibuja como prefijo, pero un lector de pantalla lee
        // el input aislado: sin esto, "1250000" no dice en qué moneda está.
        aria-label={`Importe en ${code}`}
        className={cn("pl-10 tabular-nums", className)}
        value={isEmpty && !focused ? "" : display}
        placeholder="0,00"
        onFocus={() => {
          setFocused(true)
          setDraft(value === null || value === undefined ? "" : String(value))
        }}
        onChange={(event) => {
          setDraft(event.target.value)
          onChange(parseCurrencyInput(event.target.value))
        }}
        onBlur={() => setFocused(false)}
        disabled={disabled}
      />
    </div>
  )
}
