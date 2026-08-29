"use client"

import { useEffect, useState } from "react"
import { AlertTriangle, Loader2 } from "lucide-react"
import { getGroupsEligibility, type WhatsAppGroupEligibility } from "@/lib/api/whatsapp-groups"

interface GroupEligibilityGateProps {
  channelId: number | null
  children: (eligibility: WhatsAppGroupEligibility | null, isLoading: boolean) => React.ReactNode
}

/**
 * Envuelve la UI de creación/gestión de grupos: consulta la elegibilidad del
 * canal (OBA + no-coexistencia) y expone el resultado al children en vez de
 * bloquear todo detrás de un spinner — cada acción decide cómo reaccionar
 * (deshabilitar botón, mostrar aviso, etc.).
 */
export function GroupEligibilityGate({ channelId, children }: GroupEligibilityGateProps) {
  const [eligibility, setEligibility] = useState<WhatsAppGroupEligibility | null>(null)
  const [isLoading, setIsLoading] = useState(false)

  useEffect(() => {
    if (!channelId) {
      setEligibility(null)
      return
    }
    let cancelled = false
    setIsLoading(true)
    getGroupsEligibility(channelId)
      .then((data) => {
        if (!cancelled) setEligibility(data)
      })
      .catch(() => {
        if (!cancelled) setEligibility(null)
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [channelId])

  return <>{children(eligibility, isLoading)}</>
}

export function GroupEligibilityNotice({ eligibility }: { eligibility: WhatsAppGroupEligibility }) {
  if (eligibility.status === "eligible") return null

  return (
    <div className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
      <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
      <p>{eligibility.reason_message}</p>
    </div>
  )
}

export function GroupEligibilityLoading() {
  return (
    <div className="flex items-center justify-center py-6 text-muted-foreground">
      <Loader2 className="h-4 w-4 animate-spin" />
    </div>
  )
}
