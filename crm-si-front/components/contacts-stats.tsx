"use client"

import { useEffect, useState } from "react"
import { AlertTriangle, CalendarClock, CircleCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { StatStrip } from "@/components/ui/stat-strip"
import { useTranslation } from "@/hooks/useTranslation"
import { getContactsSummary, type ContactsSummary } from "@/lib/api/contacts"

interface ContactsStatsProps {
  refreshKey?: number
  /**
   * Avisa al padre si el tenant tiene el módulo de cobranzas habilitado. Se
   * deriva del mismo summary que ya pide este componente para las tarjetas,
   * así el header no necesita repetir el request solo para saber si mostrar
   * el filtro de clientes.
   */
  onBillingAvailabilityChange?: (available: boolean) => void
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("es-AR").format(Math.round(value))
}

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

export function ContactsStats({ refreshKey = 0, onBillingAvailabilityChange }: ContactsStatsProps) {
  const { t } = useTranslation()
  const [data, setData] = useState<ContactsSummary | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const [retryKey, setRetryKey] = useState(0)

  useEffect(() => {
    let cancelled = false
    setIsLoading(true)
    setFailed(false)
    getContactsSummary()
      .then((summary) => {
        if (cancelled) return
        setData(summary)
        onBillingAvailabilityChange?.(summary.billing_al_dia !== undefined)
      })
      .catch(() => {
        if (cancelled) return
        setData(null)
        setFailed(true)
        onBillingAvailabilityChange?.(false)
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [refreshKey, retryKey, onBillingAvailabilityChange])

  // El backend omite las claves de cobranzas cuando el módulo está apagado
  // para ese tenant: sin config no se muestra la fila en vez de mostrarla
  // en 0, que se leería como "todos al día" en vez de "no configurado".
  const hasBilling = data?.billing_al_dia !== undefined
  const pending = isLoading || (!data && !failed)
  const fallback = failed ? "—" : null

  return (
    <div className="space-y-3 md:space-y-4">
      {data && hasBilling && (
        <StatStrip
          stats={[
            { label: t("contactsPage.stats.billingAlDia"), value: formatNumber(data.billing_al_dia ?? 0), icon: CircleCheck, tone: "success" },
            { label: t("contactsPage.stats.billingPorVencer"), value: formatNumber(data.billing_por_vencer ?? 0), icon: CalendarClock, tone: "warning" },
            { label: t("contactsPage.stats.billingVencido"), value: formatNumber(data.billing_vencido ?? 0), icon: AlertTriangle, tone: "destructive" },
          ]}
        />
      )}

      <StatStrip
        loading={pending}
        stats={[
          { label: t("contactsPage.stats.total"), value: data ? formatNumber(data.total_contacts) : fallback },
          { label: t("contactsPage.stats.activeLeads"), value: data ? formatNumber(data.active_leads) : fallback },
          { label: t("contactsPage.stats.qualified"), value: data ? formatNumber(data.qualified) : fallback },
          { label: t("contactsPage.stats.conversionRate"), value: data ? formatPercent(data.conversion_rate) : fallback },
        ]}
      />

      {failed && !isLoading && (
        <div className="flex items-center gap-2 text-sm text-muted-foreground" role="alert">
          <AlertTriangle className="size-4 shrink-0 text-destructive" aria-hidden="true" />
          <span>No se pudieron cargar las métricas.</span>
          <Button variant="link" size="sm" className="h-auto p-0" onClick={() => setRetryKey((key) => key + 1)}>
            Reintentar
          </Button>
        </div>
      )}
    </div>
  )
}
