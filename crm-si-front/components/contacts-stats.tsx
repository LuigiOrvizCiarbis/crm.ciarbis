"use client"

import { useEffect, useState } from "react"
import { Card, CardContent } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { AlertTriangle, CalendarClock, CircleCheck, TrendingUp, UserCheck, UserPlus, Users, type LucideIcon } from "lucide-react"
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

interface StatCardProps {
  title: string
  value: string
  icon: LucideIcon
  iconBg: string
  iconColor: string
  gradientFrom: string
  gradientTo: string
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("es-AR").format(Math.round(value))
}

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

function StatCard({ title, value, icon: Icon, iconBg, iconColor, gradientFrom, gradientTo }: StatCardProps) {
  return (
    <Card className="relative overflow-hidden border-border/50 hover:border-border transition-colors">
      <div className={`absolute inset-0 bg-gradient-to-br ${gradientFrom} ${gradientTo} opacity-5 pointer-events-none`} />
      <CardContent className="p-5 relative">
        <div className="flex items-center gap-3 mb-3">
          <div className={`flex items-center justify-center w-10 h-10 rounded-full ${iconBg}`}>
            <Icon className={`w-5 h-5 ${iconColor}`} />
          </div>
          <p className="text-sm font-medium text-muted-foreground">{title}</p>
        </div>
        <p className="text-3xl font-bold tabular-nums">{value}</p>
      </CardContent>
    </Card>
  )
}

function LoadingStat() {
  return (
    <Card className="border-border/50">
      <CardContent className="p-5 space-y-3">
        <div className="flex items-center gap-3">
          <Skeleton className="h-10 w-10 rounded-full" />
          <Skeleton className="h-4 w-32" />
        </div>
        <Skeleton className="h-9 w-24" />
      </CardContent>
    </Card>
  )
}

export function ContactsStats({ refreshKey = 0, onBillingAvailabilityChange }: ContactsStatsProps) {
  const { t } = useTranslation()
  const [data, setData] = useState<ContactsSummary | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setIsLoading(true)
    getContactsSummary()
      .then((summary) => {
        if (cancelled) return
        setData(summary)
        onBillingAvailabilityChange?.(summary.billing_al_dia !== undefined)
      })
      .catch(() => {
        if (cancelled) return
        setData(null)
        onBillingAvailabilityChange?.(false)
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [refreshKey, onBillingAvailabilityChange])

  if (isLoading || !data) {
    return (
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <LoadingStat />
        <LoadingStat />
        <LoadingStat />
        <LoadingStat />
      </div>
    )
  }

  // El backend omite las claves de cobranzas cuando el módulo está apagado
  // para ese tenant: sin config no se muestran tarjetas en vez de mostrarlas
  // en 0, que se leería como "todos al día" en vez de "no configurado".
  const hasBilling = data.billing_al_dia !== undefined

  return (
    <div className="space-y-4">
      {hasBilling && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <StatCard
            title={t("contactsPage.stats.billingAlDia")}
            value={formatNumber(data.billing_al_dia ?? 0)}
            icon={CircleCheck}
            iconBg="bg-emerald-500/15"
            iconColor="text-emerald-400"
            gradientFrom="from-emerald-500"
            gradientTo="to-teal-500"
          />
          <StatCard
            title={t("contactsPage.stats.billingPorVencer")}
            value={formatNumber(data.billing_por_vencer ?? 0)}
            icon={CalendarClock}
            iconBg="bg-amber-500/15"
            iconColor="text-amber-400"
            gradientFrom="from-amber-500"
            gradientTo="to-yellow-500"
          />
          <StatCard
            title={t("contactsPage.stats.billingVencido")}
            value={formatNumber(data.billing_vencido ?? 0)}
            icon={AlertTriangle}
            iconBg="bg-red-500/15"
            iconColor="text-red-400"
            gradientFrom="from-red-500"
            gradientTo="to-rose-500"
          />
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <StatCard
        title={t("contactsPage.stats.total")}
        value={formatNumber(data.total_contacts)}
        icon={Users}
        iconBg="bg-cyan-500/15"
        iconColor="text-cyan-400"
        gradientFrom="from-cyan-500"
        gradientTo="to-blue-500"
      />
      <StatCard
        title={t("contactsPage.stats.activeLeads")}
        value={formatNumber(data.active_leads)}
        icon={UserPlus}
        iconBg="bg-emerald-500/15"
        iconColor="text-emerald-400"
        gradientFrom="from-emerald-500"
        gradientTo="to-green-500"
      />
      <StatCard
        title={t("contactsPage.stats.qualified")}
        value={formatNumber(data.qualified)}
        icon={UserCheck}
        iconBg="bg-violet-500/15"
        iconColor="text-violet-400"
        gradientFrom="from-violet-500"
        gradientTo="to-purple-500"
      />
      <StatCard
        title={t("contactsPage.stats.conversionRate")}
        value={formatPercent(data.conversion_rate)}
        icon={TrendingUp}
        iconBg="bg-orange-500/15"
        iconColor="text-orange-400"
        gradientFrom="from-orange-500"
        gradientTo="to-amber-500"
      />
      </div>
    </div>
  )
}
