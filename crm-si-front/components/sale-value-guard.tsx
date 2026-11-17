"use client"

import { useCallback, useId, useRef, useState, type ReactNode } from "react"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import type { PipelineStage } from "@/lib/api/pipeline"

/** null = el usuario canceló el movimiento; value ausente = sigue sin valor. */
export type SaleValueDecision = { value?: number } | null

/**
 * Ganar sin valor manda a Meta una venta de $0 que después no se corrige
 * (cada venta se informa una sola vez): conviene pedir el monto antes.
 */
export function needsSaleValue(outcome: PipelineStage["outcome"] | undefined, value: number | string | null | undefined): boolean {
  return outcome === "won" && !(Number(value) > 0)
}

/**
 * Diálogo que pide el valor de la venta antes de mover a una etapa Ganado.
 * `confirmSaleValue` resuelve con la decisión; `dialog` se renderiza en el
 * componente que lo usa.
 */
export function useSaleValueGuard(): {
  confirmSaleValue: (name: string) => Promise<SaleValueDecision>
  dialog: ReactNode
} {
  const { t } = useTranslation()
  const canEditValue = usePermission("opportunities.update")
  const inputId = useId()
  const [subject, setSubject] = useState<string | null>(null)
  const [amount, setAmount] = useState("")
  const resolveRef = useRef<((decision: SaleValueDecision) => void) | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const settle = (decision: SaleValueDecision) => {
    resolveRef.current?.(decision)
    resolveRef.current = null
    setSubject(null)
  }

  // Executor y no Promise.withResolvers: este último no existe en Safari < 17.4,
  // que siguen usando vendedores en iPhone.
  const confirmSaleValue = useCallback(
    (name: string) =>
      new Promise<SaleValueDecision>((resolve) => {
        resolveRef.current = resolve
        setAmount("")
        setSubject(name)
      }),
    []
  )

  const parsedAmount = Number(amount)
  const canSave = canEditValue && amount.trim() !== "" && parsedAmount > 0

  const dialog = (
    <AlertDialog open={subject !== null} onOpenChange={(open) => !open && settle(null)}>
      <AlertDialogContent
        // Radix enfoca "Cancelar" al abrir; el foco va al monto, que es lo que se pide.
        onOpenAutoFocus={(event) => {
          if (!inputRef.current) return
          event.preventDefault()
          inputRef.current.focus()
        }}
      >
        <AlertDialogHeader>
          <AlertDialogTitle>{t("pipeline.saleValue.title")}</AlertDialogTitle>
          <AlertDialogDescription>{t("pipeline.saleValue.description", { name: subject ?? "" })}</AlertDialogDescription>
        </AlertDialogHeader>

        {canEditValue ? (
          <form
            className="space-y-2"
            onSubmit={(event) => {
              event.preventDefault()
              if (canSave) settle({ value: parsedAmount })
            }}
          >
            <label htmlFor={inputId} className="text-sm font-medium">
              {t("pipeline.saleValue.label")}
            </label>
            <Input
              id={inputId}
              type="number"
              inputMode="decimal"
              min={0}
              step="any"
              ref={inputRef}
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              className="tabular-nums"
            />
          </form>
        ) : (
          <p className="text-sm text-muted-foreground">{t("pipeline.saleValue.noPermission")}</p>
        )}

        <AlertDialogFooter>
          <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
          <Button variant="outline" onClick={() => settle({})}>
            {t("pipeline.saleValue.skip")}
          </Button>
          {canEditValue && (
            <AlertDialogAction
              disabled={!canSave}
              onClick={(event) => {
                // El cierre lo maneja settle: sin esto Radix cerraría antes de resolver.
                event.preventDefault()
                if (canSave) settle({ value: parsedAmount })
              }}
            >
              {t("pipeline.saleValue.save")}
            </AlertDialogAction>
          )}
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )

  return { confirmSaleValue, dialog }
}
