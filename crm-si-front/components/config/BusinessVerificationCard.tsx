"use client"

import { useCallback, useEffect, useState } from "react"
import { BadgeCheck, ChevronRight, ExternalLink, RefreshCw, ShieldCheck } from "lucide-react"

import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { ChannelType } from "@/data/enums"
import type { Channel } from "@/data/types"
import {
  getBusinessVerification,
  getChannels,
  type BusinessVerification,
  type BusinessVerificationStatus,
} from "@/lib/api/channels"
import { useIsAdmin } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"

type BadgeVariant = "default" | "secondary" | "destructive" | "outline"

// Estados que se muestran como badge verde/amarillo/rojo/gris.
const STATUS_VARIANT: Record<BusinessVerificationStatus, BadgeVariant> = {
  verified: "default",
  pending: "secondary",
  not_verified: "outline",
  failed: "destructive",
  business_id_missing: "outline",
  permission_missing: "destructive",
  token_invalid: "destructive",
  unknown: "outline",
}

interface Row {
  channel: Channel
  loading: boolean
  data?: BusinessVerification
  error?: boolean
}

export function BusinessVerificationCard() {
  const { t } = useTranslation()
  const isAdmin = useIsAdmin()
  const [rows, setRows] = useState<Row[]>([])
  const [loadingChannels, setLoadingChannels] = useState(true)

  const load = useCallback(async () => {
    setLoadingChannels(true)
    try {
      const channels = await getChannels()
      const whatsappChannels = channels.filter(
        (c) => c.type === ChannelType.WHATSAPP,
      )

      setRows(whatsappChannels.map((channel) => ({ channel, loading: true })))
      setLoadingChannels(false)

      // Consultar el estado de cada canal en paralelo.
      await Promise.all(
        whatsappChannels.map(async (channel) => {
          try {
            const data = await getBusinessVerification(channel.id)
            setRows((prev) =>
              prev.map((r) =>
                r.channel.id === channel.id ? { ...r, loading: false, data } : r,
              ),
            )
          } catch {
            setRows((prev) =>
              prev.map((r) =>
                r.channel.id === channel.id
                  ? { ...r, loading: false, error: true }
                  : r,
              ),
            )
          }
        }),
      )
    } catch {
      setRows([])
      setLoadingChannels(false)
    }
  }, [])

  useEffect(() => {
    if (isAdmin) void load()
  }, [isAdmin, load])

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
          onClick={() => void load()}
          disabled={loadingChannels}
        >
          <RefreshCw className="mr-1 size-4" />
          {t("businessVerification.refresh")}
        </Button>
      }
    >
      {loadingChannels ? (
        <div className="space-y-3">
          <Skeleton className="h-16 w-full rounded-lg" />
          <Skeleton className="h-16 w-full rounded-lg" />
        </div>
      ) : rows.length === 0 ? (
        <p className="py-6 text-sm text-muted-foreground">
          {t("businessVerification.empty")}
        </p>
      ) : (
        <div className="divide-y divide-border border-y border-border">
          {rows.map((row) => (
            <VerificationRow key={row.channel.id} row={row} />
          ))}
        </div>
      )}
    </SettingsBlock>
  )
}

function VerificationRow({ row }: { row: Row }) {
  const { t } = useTranslation()
  const { channel, loading, data, error } = row

  const phone =
    channel.whatsapp_config?.display_phone_number ??
    channel.whatsapp_config?.phone_number_id ??
    ""

  // Guía de pasos: sólo tiene sentido cuando el negocio aún no está verificado
  // y la consulta trajo datos (no en loading ni error).
  const showHowTo = !loading && !error && !!data && data.status !== "verified"

  return (
    <div className="space-y-3 py-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 space-y-0.5">
          <p className="truncate text-sm font-medium text-foreground">
            {data?.business_name || channel.name}
          </p>
          {phone && (
            <p className="truncate text-xs text-muted-foreground">{phone}</p>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-3">
          {loading ? (
            <Skeleton className="h-6 w-24 rounded-full" />
          ) : error ? (
            <Badge variant="destructive">
              {t("businessVerification.status.unknown")}
            </Badge>
          ) : data ? (
            <>
              <Badge
                variant={STATUS_VARIANT[data.status]}
                className="gap-1"
              >
                {data.status === "verified" && <BadgeCheck className="size-3" />}
                {t(`businessVerification.status.${data.status}`)}
              </Badge>
              {data.verify_url && (
                <Button asChild size="sm" variant="outline">
                  <a
                    href={data.verify_url}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {t("businessVerification.verifyOnMeta")}
                    <ExternalLink className="ml-1 size-3.5" />
                  </a>
                </Button>
              )}
            </>
          ) : null}
        </div>
      </div>

      {showHowTo && <HowToVerify />}
    </div>
  )
}

// Tutorial colapsable con los pasos para verificar el negocio en Meta.
function HowToVerify() {
  const { t } = useTranslation()

  return (
    <details className="group rounded-md border border-border/60 bg-muted/40">
      <summary className="flex cursor-pointer list-none items-center gap-1.5 px-3 py-2 text-sm font-medium text-foreground [&::-webkit-details-marker]:hidden">
        <ChevronRight className="size-4 text-muted-foreground transition-transform group-open:rotate-90" />
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
