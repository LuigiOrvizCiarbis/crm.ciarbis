"use client"

import { PlatformIcon } from "@/components/chat/PlatformIcon"
import { PanelSection } from "@/components/dashboard/PanelSection"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardOmnichannel } from "@/lib/api/dashboard"
import { channelKey } from "@/lib/utils/channelHelpers"

interface ChannelBreakdownProps {
  channels?: DashboardOmnichannel[]
  isLoading: boolean
  className?: string
}

/**
 * Conversaciones nuevas del período por canal. Con un solo canal la sección se oculta: un 100% no
 * ayuda a decidir nada.
 */
export function ChannelBreakdown({ channels, isLoading, className }: ChannelBreakdownProps) {
  const { t } = useTranslation()
  const rows = [...(channels ?? [])].filter((row) => row.conversations_count > 0).sort((a, b) => b.conversations_count - a.conversations_count)
  const total = rows.reduce((sum, row) => sum + row.conversations_count, 0)
  const max = Math.max(1, ...rows.map((row) => row.conversations_count))

  if (!isLoading && rows.length === 1) return null

  const renderBody = () => {
    if (isLoading && !channels) {
      return (
        <div className="space-y-4">
          {[0, 1, 2].map((row) => <Skeleton key={row} className="h-5 w-full" />)}
        </div>
      )
    }
    if (rows.length === 0) return <p className="text-sm text-muted-foreground">{t("dashboard.channels.empty")}</p>

    return (
      <ul className="space-y-4">
        {rows.map((row) => {
          const share = Math.round((row.conversations_count / total) * 100)
          return (
            <li key={row.channel_id ?? "none"}>
              <div className="flex items-center gap-2 text-sm">
                <PlatformIcon type={channelKey(row.channel_type)} className="size-4 shrink-0" aria-hidden="true" />
                <span className="min-w-0 flex-1 truncate">{row.channel_name ?? t("dashboard.channels.unnamed")}</span>
                <span className="font-medium tabular-nums">{row.conversations_count.toLocaleString("es-AR")}</span>
                <span className="w-10 text-right text-xs tabular-nums text-muted-foreground">{share}%</span>
              </div>
              <div className="mt-1.5 h-1.5 rounded-full bg-muted" aria-hidden="true">
                <div className="h-full rounded-full bg-chart-2" style={{ width: `${(row.conversations_count / max) * 100}%` }} />
              </div>
            </li>
          )
        })}
      </ul>
    )
  }

  return (
    <PanelSection
      id="panel-channels"
      title={t("dashboard.channels.title")}
      description={t("dashboard.channels.description")}
      busy={isLoading}
      className={className}
    >
      {renderBody()}
    </PanelSection>
  )
}
