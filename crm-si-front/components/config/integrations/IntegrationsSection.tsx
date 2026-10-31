"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { ArrowLeft, Blocks, ChevronRight } from "lucide-react"

import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Skeleton } from "@/components/ui/skeleton"
import { useIsAdmin } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import {
  INTEGRATIONS,
  type IntegrationDefinition,
  type IntegrationStatus,
} from "./registry"

// Las que requieren atención primero, las no disponibles al final.
const STATUS_ORDER: Record<IntegrationStatus, number> = {
  error: 0,
  connected: 1,
  available: 2,
  comingSoon: 3,
}

type StatusMap = Partial<Record<string, IntegrationStatus>>

export function IntegrationsSection() {
  const { t } = useTranslation()
  const isAdmin = useIsAdmin()
  const [openId, setOpenId] = useState<string | null>(null)
  const [statuses, setStatuses] = useState<StatusMap>({})

  // Las integraciones a nivel tenant (credenciales compartidas) son solo para
  // admins; las personales por usuario (ej. Google Calendar) son para todos.
  const visibleIntegrations = useMemo(
    () => INTEGRATIONS.filter((def) => !def.adminOnly || isAdmin),
    [isAdmin],
  )

  const refreshStatuses = useCallback(async () => {
    const entries = await Promise.all(
      visibleIntegrations.map(async (def): Promise<[string, IntegrationStatus]> => {
        try {
          return [def.id, await def.fetchStatus()]
        } catch {
          return [def.id, "error"]
        }
      }),
    )
    setStatuses(Object.fromEntries(entries))
  }, [visibleIntegrations])

  useEffect(() => {
    const [, detailId] = window.location.hash.slice(1).split("/")
    if (detailId && visibleIntegrations.some((def) => def.id === detailId)) {
      setOpenId(detailId)
    }
    void refreshStatuses()
  }, [refreshStatuses, visibleIntegrations])

  const openDetail = (id: string) => {
    setOpenId(id)
    window.history.replaceState(null, "", `#integrations/${id}`)
  }

  const closeDetail = () => {
    setOpenId(null)
    window.history.replaceState(null, "", "#integrations")
    // La config pudo cambiar dentro del detalle (conectar/desconectar).
    void refreshStatuses()
  }

  const sortedIntegrations = useMemo(() => {
    return [...visibleIntegrations].sort((a, b) => {
      const statusA = statuses[a.id]
      const statusB = statuses[b.id]
      if (!statusA || !statusB) return 0
      return STATUS_ORDER[statusA] - STATUS_ORDER[statusB]
    })
  }, [statuses, visibleIntegrations])

  const openIntegration = openId
    ? visibleIntegrations.find((def) => def.id === openId)
    : undefined

  if (openIntegration) {
    return (
      <IntegrationDetail
        integration={openIntegration}
        status={statuses[openIntegration.id]}
        onBack={closeDetail}
      />
    )
  }

  return (
    <SettingsBlock
      title={t("settings.integrationsHub.directoryTitle")}
      description={t("settings.integrationsHub.directoryDescription")}
      icon={Blocks}
    >
      <ul className="divide-y divide-border border-y border-border motion-safe:animate-in motion-safe:fade-in motion-safe:duration-200">
        {sortedIntegrations.map((def) => (
          <DirectoryRow
            key={def.id}
            integration={def}
            status={statuses[def.id]}
            onOpen={() => openDetail(def.id)}
          />
        ))}
      </ul>
    </SettingsBlock>
  )
}

interface DirectoryRowProps {
  integration: IntegrationDefinition
  status?: IntegrationStatus
  onOpen: () => void
}

function DirectoryRow({ integration, status, onOpen }: DirectoryRowProps) {
  const { t } = useTranslation()
  const Icon = integration.icon
  const isComingSoon = status === "comingSoon"

  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        disabled={isComingSoon}
        className={cn(
          "group flex w-full items-center gap-4 px-2 py-4 text-left outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
          isComingSoon ? "opacity-60" : "hover:bg-muted/50",
        )}
      >
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <Icon className="size-5" />
        </span>
        <span className="min-w-0 flex-1 space-y-0.5">
          <span className="block text-sm font-medium text-foreground">
            {t(integration.nameKey)}
          </span>
          <span className="block truncate text-sm text-muted-foreground">
            {t(integration.descriptionKey)}
          </span>
        </span>
        <StatusBadge status={status} />
        {!isComingSoon && (
          <>
            <span className="hidden shrink-0 text-sm font-medium text-primary sm:inline">
              {status === "available"
                ? t("settings.integrationsHub.connect")
                : t("settings.integrationsHub.configure")}
            </span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" />
          </>
        )}
      </button>
    </li>
  )
}

interface IntegrationDetailProps {
  integration: IntegrationDefinition
  status?: IntegrationStatus
  onBack: () => void
}

function IntegrationDetail({
  integration,
  status,
  onBack,
}: IntegrationDetailProps) {
  const { t } = useTranslation()
  const Icon = integration.icon
  const Detail = integration.Detail

  return (
    <div className="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-right-2 motion-safe:duration-200">
      <button
        type="button"
        onClick={onBack}
        className="flex items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground outline-none transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
      >
        <ArrowLeft className="size-4" />
        {t("settings.integrationsHub.back")}
      </button>

      <div className="mt-5 flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <Icon className="size-5" />
        </span>
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-3">
            <h3 className="text-lg font-semibold text-foreground">
              {t(integration.nameKey)}
            </h3>
            <StatusBadge status={status} />
          </div>
          <p className="max-w-[70ch] text-sm leading-6 text-muted-foreground">
            {t(integration.descriptionKey)}
          </p>
        </div>
      </div>

      <div className="mt-6 w-full border-t border-border pt-6">
        <Detail />
      </div>
    </div>
  )
}

const STATUS_STYLES: Record<IntegrationStatus, { dot: string; text: string }> =
  {
    connected: {
      dot: "bg-emerald-500",
      text: "text-emerald-600 dark:text-emerald-400",
    },
    available: { dot: "bg-muted-foreground/40", text: "text-muted-foreground" },
    error: { dot: "bg-destructive", text: "text-destructive" },
    comingSoon: {
      dot: "bg-muted-foreground/40",
      text: "text-muted-foreground",
    },
  }

function StatusBadge({ status }: { status?: IntegrationStatus }) {
  const { t } = useTranslation()

  if (!status) {
    return <Skeleton className="h-4 w-20 shrink-0 rounded-full" />
  }

  const styles = STATUS_STYLES[status]

  return (
    <span
      className={cn(
        "flex shrink-0 items-center gap-1.5 text-xs font-medium",
        styles.text,
      )}
    >
      <span
        className={cn("size-1.5 rounded-full", styles.dot)}
        aria-hidden="true"
      />
      {t(`settings.integrationsHub.status.${status}`)}
    </span>
  )
}
