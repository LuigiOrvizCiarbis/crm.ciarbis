"use client"

import { useCallback, useEffect, useState } from "react"
import { AlertCircle, ChevronRight, ExternalLink, RefreshCw, ShieldCheck } from "lucide-react"

import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { ChannelType } from "@/data/enums"
import type { Channel } from "@/data/types"
import {
  getBusinessVerification,
  getChannels,
  type BusinessVerification,
} from "@/lib/api/channels"
import { useIsAdmin } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { presentVerification, VERIFICATION_TONE_CLASSES } from "@/lib/business-verification"

/** La verificación es del Business Portfolio: los números que lo comparten van juntos. */
interface BusinessGroup {
  key: string
  name: string
  phones: string[]
  /** `null` cuando la consulta a nuestro backend falló. */
  verification: BusinessVerification | null
}

function channelPhone(channel: Channel): string {
  return (
    channel.whatsapp_config?.display_phone_number ??
    channel.whatsapp_config?.phone_number_id ??
    channel.phone ??
    ""
  )
}

function groupByBusiness(
  results: { channel: Channel; verification: BusinessVerification | null }[],
): BusinessGroup[] {
  const groups = new Map<string, BusinessGroup>()

  for (const { channel, verification } of results) {
    const key = verification?.business_id ?? `channel-${channel.id}`
    const phone = channelPhone(channel)
    const group = groups.get(key)

    if (group) {
      if (phone) group.phones.push(phone)
      continue
    }

    groups.set(key, {
      key,
      name: verification?.business_name || channel.name,
      phones: phone ? [phone] : [],
      verification,
    })
  }

  return [...groups.values()]
}

async function loadVerifications(): Promise<BusinessGroup[]> {
  const channels = (await getChannels()).filter((c) => c.type === ChannelType.WHATSAPP)

  const results = await Promise.all(
    channels.map(async (channel) => ({
      channel,
      verification: await getBusinessVerification(channel.id).catch(() => null),
    })),
  )

  return groupByBusiness(results)
}

export function BusinessVerificationCard() {
  const { t } = useTranslation()
  const isAdmin = useIsAdmin()
  const [groups, setGroups] = useState<BusinessGroup[] | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  // El estado inicial ya es "cargando": el efecto sólo escribe estado después
  // de la respuesta, y `refresh` marca la recarga antes de pedir de nuevo.
  const fetchGroups = useCallback(async () => {
    try {
      setGroups(await loadVerifications())
      setLoadError(false)
    } catch {
      setLoadError(true)
    } finally {
      setLoading(false)
    }
  }, [])

  // Al actualizar se mantiene la lista anterior en pantalla: el skeleton sólo
  // aparece en la primera carga para no hacer saltar el bloque.
  const refresh = () => {
    setLoading(true)
    setLoadError(false)
    void fetchGroups()
  }

  useEffect(() => {
    if (isAdmin) void fetchGroups()
  }, [isAdmin, fetchGroups])

  // Sólo admins gestionan la verificación (mismo criterio que conectar canales).
  if (!isAdmin) return null

  return (
    <SettingsBlock
      title={t("businessVerification.title")}
      description={t("businessVerification.subtitle")}
      icon={ShieldCheck}
      action={
        <Button
          variant="ghost"
          size="sm"
          onClick={refresh}
          disabled={loading}
          className="-mr-2"
        >
          <RefreshCw className={cn("mr-1 size-4", loading && "motion-safe:animate-spin")} />
          {t("businessVerification.refresh")}
        </Button>
      }
    >
      {loadError ? (
        <div className="flex flex-col items-start gap-3 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
          <span className="flex items-center gap-2 text-destructive">
            <AlertCircle className="size-4 shrink-0" />
            {t("businessVerification.loadError")}
          </span>
          <Button size="sm" variant="outline" onClick={refresh}>
            {t("businessVerification.retry")}
          </Button>
        </div>
      ) : groups === null ? (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full rounded-md" />
          <Skeleton className="h-20 w-full rounded-md" />
        </div>
      ) : groups.length === 0 ? (
        <p className="py-6 text-sm text-muted-foreground">
          {t("businessVerification.empty")}
        </p>
      ) : (
        <ul
          className={cn(
            "divide-y divide-border border-y border-border transition-opacity duration-200",
            loading && "opacity-60",
          )}
          aria-live="polite"
          aria-busy={loading}
        >
          {groups.map((group) => (
            <BusinessRow key={group.key} group={group} />
          ))}
        </ul>
      )}
    </SettingsBlock>
  )
}

function BusinessRow({ group }: { group: BusinessGroup }) {
  const { t } = useTranslation()
  const { state, tone, icon: Icon, cta, showHowTo } = presentVerification(group.verification)
  const verifyUrl = group.verification?.verify_url
  const toneClasses = VERIFICATION_TONE_CLASSES[tone]

  return (
    <li className="flex flex-wrap items-start gap-x-3 gap-y-3 py-4">
      <div
        className={cn("flex size-9 shrink-0 items-center justify-center rounded-md", toneClasses.tile)}
        aria-hidden
      >
        <Icon className="size-4" />
      </div>

      <div className="min-w-0 flex-1 basis-64 space-y-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <p className="truncate font-medium text-foreground">{group.name}</p>
          <span
            className={cn(
              "inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap",
              toneClasses.pill,
            )}
          >
            {t(`businessVerification.states.${state}.label`)}
          </span>
        </div>
        {group.phones.length > 0 && (
          <p className="font-mono text-xs text-muted-foreground">
            {group.phones.join(" · ")}
          </p>
        )}
        <p className="max-w-[68ch] pt-1 text-pretty text-sm text-muted-foreground">
          {t(`businessVerification.states.${state}.detail`)}
        </p>
      </div>

      {cta && verifyUrl && (
        <Button asChild size="sm" variant={cta === "open" ? "outline" : "default"} className="ml-12 sm:ml-0">
          <a href={verifyUrl} target="_blank" rel="noopener noreferrer">
            {t(`businessVerification.cta.${cta}`)}
            <ExternalLink className="ml-1 size-3.5" aria-hidden />
          </a>
        </Button>
      )}

      {/* Fila propia: en pantallas angostas el botón queda arriba de la guía, no debajo. */}
      {showHowTo && (
        <div className="basis-full pl-12">
          <HowToVerify />
        </div>
      )}
    </li>
  )
}

// Tutorial colapsable con los pasos para verificar el negocio en Meta.
function HowToVerify() {
  const { t } = useTranslation()

  return (
    <details className="group max-w-xl rounded-md border border-border/60 bg-muted/40">
      <summary className="flex cursor-pointer list-none items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-foreground focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-webkit-details-marker]:hidden">
        <ChevronRight className="size-4 text-muted-foreground transition-transform duration-200 group-open:rotate-90 motion-reduce:transition-none" />
        {t("businessVerification.howTo.trigger")}
      </summary>
      <div className="space-y-3 px-3 pb-3 pt-1 text-sm text-muted-foreground">
        <p>{t("businessVerification.howTo.intro")}</p>
        <ol className="list-decimal space-y-1.5 pl-5">
          <li>{t("businessVerification.howTo.step1")}</li>
          <li>{t("businessVerification.howTo.step2")}</li>
          <li>{t("businessVerification.howTo.step3")}</li>
          <li>{t("businessVerification.howTo.step4")}</li>
        </ol>
        <p className="text-xs">{t("businessVerification.howTo.note")}</p>
      </div>
    </details>
  )
}
