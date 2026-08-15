"use client"

import { useCallback, useEffect, useState } from "react"
import { AlertCircle, Clock3, Loader2, MessageSquare, RefreshCw } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Skeleton } from "@/components/ui/skeleton"
import { ChannelType } from "@/data/enums"
import type { Channel } from "@/data/types"
import { getChannels, getContactSync, retryContactSync, retryHistorySync, type ContactSync } from "@/lib/api/channels"
import { useIsAdmin } from "@/hooks/usePermission"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"

interface Row {
  channel: Channel
  loading: boolean
  data?: ContactSync
  error?: boolean
  retrying?: boolean
  retryingHistory?: boolean
}

export function ChannelsCard() {
  const isAdmin = useIsAdmin()
  const { t, language } = useTranslation()
  const { addToast } = useToast()
  const [rows, setRows] = useState<Row[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(false)
    try {
      const channels = (await getChannels()).filter((channel) => channel.type === ChannelType.WHATSAPP)
      setRows(channels.map((channel) => ({ channel, loading: true })))
      setLoading(false)
      await Promise.all(channels.map(async (channel) => {
        try {
          const data = await getContactSync(channel.id)
          setRows((current) => current.map((row) => row.channel.id === channel.id ? { ...row, loading: false, data } : row))
        } catch {
          setRows((current) => current.map((row) => row.channel.id === channel.id ? { ...row, loading: false, error: true } : row))
        }
      }))
    } catch {
      setRows([])
      setLoadError(true)
      setLoading(false)
    }
  }, [])

  useEffect(() => { if (isAdmin) void load() }, [isAdmin, load])

  const retry = async (channelId: number) => {
    setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, retrying: true } : row))
    try {
      await retryContactSync(channelId)
      addToast({ type: "success", title: t("settings.contactSync.retryStarted") })
      const data = await getContactSync(channelId)
      setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, data, retrying: false } : row))
    } catch (error) {
      const message = error instanceof Error ? error.message : t("settings.contactSync.retryError")
      try {
        // Un 409 puede significar que otra petición ya dejó el sync en curso.
        // La API es la fuente de verdad: no convertimos ese estado en `failed`.
        const data = await getContactSync(channelId)
        setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, data, retrying: false } : row))
      } catch {
        setRows((current) => current.map((row) => row.channel.id === channelId ? {
          ...row,
          retrying: false,
          data: row.data ? { ...row.data, error: message } : row.data,
        } : row))
      }
    }
  }

  const retryHistory = async (channelId: number) => {
    setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, retryingHistory: true } : row))
    try {
      await retryHistorySync(channelId)
      addToast({ type: "success", title: t("settings.contactSync.historyRetryStarted") })
      const data = await getContactSync(channelId)
      setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, data, retryingHistory: false } : row))
    } catch (error) {
      const message = error instanceof Error ? error.message : t("settings.contactSync.historyRetryError")
      try {
        // Mismo criterio que retry(): un 409 puede significar que otra
        // petición ya dejó el sync en curso. La API es la fuente de verdad.
        const data = await getContactSync(channelId)
        setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, data, retryingHistory: false } : row))
      } catch {
        setRows((current) => current.map((row) => row.channel.id === channelId ? {
          ...row,
          retryingHistory: false,
          data: row.data ? { ...row.data, error: message } : row.data,
        } : row))
      }
    }
  }

  const refreshRow = async (channelId: number) => {
    setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, loading: true, error: false } : row))
    try {
      const data = await getContactSync(channelId)
      setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, loading: false, data } : row))
    } catch {
      setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, loading: false, error: true } : row))
    }
  }

  if (!isAdmin) return null

  return (
    <SettingsBlock
      title={t("settings.channels")}
      description={t("settings.contactSync.subtitle")}
      icon={MessageSquare}
      action={
        <Button variant="ghost" size="sm" aria-label={t("settings.contactSync.refresh")} onClick={() => void load()} disabled={loading}>
          <RefreshCw className="mr-1 size-4" />{t("settings.contactSync.refresh")}
        </Button>
      }
    >
      {loading ? <div className="space-y-3"><Skeleton className="h-20 w-full rounded-md" /><Skeleton className="h-20 w-full rounded-md" /></div> : loadError ? (
        <div className="flex flex-col items-start gap-3 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
          <span className="flex items-center gap-2 text-destructive"><AlertCircle className="size-4 shrink-0" />{t("settings.contactSync.loadError")}</span>
          <Button size="sm" variant="outline" onClick={() => void load()}>{t("settings.contactSync.retryQuery")}</Button>
        </div>
      ) : rows.length === 0 ? (
        <p className="py-6 text-sm text-muted-foreground">{t("settings.contactSync.empty")}</p>
      ) : <div className="divide-y divide-border border-y border-border">{rows.map((row) => <ChannelRow key={row.channel.id} row={row} onRetry={() => void retry(row.channel.id)} onRetryHistory={() => void retryHistory(row.channel.id)} onRefresh={() => void refreshRow(row.channel.id)} onReconnect={() => addToast({ type: "info", title: t("settings.contactSync.reconnect"), description: t("settings.contactSync.reconnectHint") })} language={language} t={t} />)}</div>}
    </SettingsBlock>
  )
}

function ChannelRow({ row, onRetry, onRetryHistory, onRefresh, t, onReconnect, language }: { row: Row; onRetry: () => void; onRetryHistory: () => void; onRefresh: () => void; onReconnect: () => void; language: string; t: (key: string, params?: Record<string, string | number>) => string }) {
  const sync = row.data
  const name = row.channel.name || t("settings.contactSync.whatsapp")
  const phone = row.channel.whatsapp_config?.display_phone_number || row.channel.phone

  return <div className="py-4 first:pt-0 last:pb-0" aria-live="polite">
    <div className="flex items-start gap-3">
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"><MessageSquare className="h-4 w-4" /></div>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <p className="truncate font-medium">{name}</p>
          <Badge variant="outline" className="text-xs font-normal">{row.channel.status === "active" ? t("settings.channelActive") : t("settings.contactSync.disconnected")}</Badge>
        </div>
        <p className="mt-0.5 truncate font-mono text-xs text-muted-foreground">{phone || "—"}</p>
      </div>
    </div>
    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 pl-12 text-sm text-muted-foreground">
      {row.loading ? <Skeleton className="h-5 w-48" /> : row.error ? <span className="flex items-center gap-1 text-destructive"><AlertCircle className="h-4 w-4" />{t("settings.contactSync.loadError")}</span> : <SyncStatus sync={sync} language={language} t={t} />}
      {!row.loading && row.error && <Button size="sm" variant="outline" onClick={onRefresh}><RefreshCw className="mr-1 h-4 w-4" />{t("settings.contactSync.retryQuery")}</Button>}
      {!row.loading && sync && sync.status !== "completed" && sync.status !== "not_applicable" && sync.can_retry && <Button size="sm" onClick={onRetry} disabled={row.retrying}>
        {row.retrying ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-1 h-4 w-4" />}{t("settings.contactSync.retry")}
      </Button>}
      {!row.loading && sync?.status === "failed" && !sync.can_retry && <Button size="sm" variant="outline" onClick={onReconnect}>{t("settings.contactSync.reconnect")}</Button>}
      {/* Independiente del botón de contactos: el historial puede quedar
          `failed` (p. ej. rate limit) mientras los contactos ya están
          `completed`, y sin esto no había forma de reintentarlo. */}
      {!row.loading && sync?.history_status === "failed" && sync.history_can_retry && <Button size="sm" variant="outline" onClick={onRetryHistory} disabled={row.retryingHistory}>
        {row.retryingHistory ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-1 h-4 w-4" />}{t("settings.contactSync.historyRetry")}
      </Button>}
    </div>
  </div>
}

function SyncStatus({ sync, language, t }: { sync?: ContactSync; language: string; t: (key: string, params?: Record<string, string | number>) => string }) {
  if (!sync) return null
  return <div className="flex flex-col gap-0.5">
    <ContactSyncLine sync={sync} language={language} t={t} />
    <HistorySyncLine sync={sync} t={t} />
  </div>
}

function ContactSyncLine({ sync, language, t }: { sync: ContactSync; language: string; t: (key: string, params?: Record<string, string | number>) => string }) {
  if (sync.status === "completed") return <span className="font-mono text-xs text-muted-foreground">{t("settings.contactSync.completed", { count: sync.contacts_imported })}</span>
  if (sync.status === "syncing") return <span className="flex items-center gap-1"><Clock3 className="h-4 w-4" />{t("settings.contactSync.syncing")}{sync.window_expires_at ? ` · ${t("settings.contactSync.until", { date: new Date(sync.window_expires_at).toLocaleString(language === "es" ? "es-AR" : "en-US") })}` : ""}</span>
  if (sync.status === "pending") return <span>{t("settings.contactSync.pending")}</span>
  if (sync.status === "failed") {
    if (!sync.can_retry) return <span className="text-destructive">{t("settings.contactSync.expired")}</span>
    // error_code traduce el mensaje en vez de mostrar el texto crudo de Meta
    // (p. ej. "(#4) Application request limit reached" no le dice al usuario
    // qué hacer). Sin código conocido, el texto de Meta sigue siendo lo mejor
    // que tenemos: mejor mostrarlo que ocultarlo.
    const message = sync.error_code === "rate_limit" ? t("settings.contactSync.rateLimit") : (sync.error || t("settings.contactSync.failed"))
    return <span className="text-destructive">{message}</span>
  }
  return null
}

// El sync de historial es un evento separado del de contactos (ver
// ContactSync en lib/api/channels.ts): un canal puede traer conversaciones de
// números que nunca estuvieron en la agenda del teléfono. Sin esta línea, un
// onboarding que sólo trajo 1 contacto nuevo pero 97 mensajes de historial se
// ve como si casi no hubiera traído nada.
function HistorySyncLine({ sync, t }: { sync: ContactSync; t: (key: string, params?: Record<string, string | number>) => string }) {
  if (!sync.history_status || sync.history_status === "pending") return null
  if (sync.history_status === "syncing") return <span className="flex items-center gap-1 text-xs"><Clock3 className="h-3 w-3" />{t("settings.contactSync.historySyncing")}</span>
  if (sync.history_status === "completed") return <span className="font-mono text-xs text-muted-foreground">{t("settings.contactSync.historyCompleted", { count: sync.history_messages_imported })}</span>
  if (sync.history_status === "failed") return <span className="text-xs text-destructive">{t("settings.contactSync.historyFailed")}</span>
  return null
}
