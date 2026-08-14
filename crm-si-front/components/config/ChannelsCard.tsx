"use client"

import { useCallback, useEffect, useState } from "react"
import { AlertCircle, Clock3, Loader2, MessageSquare, RefreshCw } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { ChannelType } from "@/data/enums"
import type { Channel } from "@/data/types"
import { getChannels, getContactSync, retryContactSync, type ContactSync } from "@/lib/api/channels"
import { useIsAdmin } from "@/hooks/usePermission"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"

interface Row {
  channel: Channel
  loading: boolean
  data?: ContactSync
  error?: boolean
  retrying?: boolean
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
      setRows((current) => current.map((row) => row.channel.id === channelId ? { ...row, retrying: false, data: row.data ? { ...row.data, status: "failed", error: message } : row.data } : row))
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
    <Card className="rounded-xl border-border shadow-sm">
      <CardHeader className="gap-1.5 px-5 pb-4 pt-5 sm:px-6 sm:pt-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <CardTitle className="flex items-center gap-2 text-base sm:text-lg">
              <MessageSquare className="h-4 w-4 text-muted-foreground sm:h-5 sm:w-5" />
              {t("settings.channels")}
            </CardTitle>
            <p className="mt-1 max-w-prose text-sm text-muted-foreground">{t("settings.contactSync.subtitle")}</p>
          </div>
          <Button variant="ghost" size="sm" aria-label={t("settings.contactSync.refresh")} onClick={() => void load()} disabled={loading}>
            <RefreshCw className="mr-1 h-4 w-4" />{t("settings.contactSync.refresh")}
          </Button>
        </div>
      </CardHeader>
      <CardContent className="px-5 pb-5 sm:px-6 sm:pb-6">
        {loading ? <div className="space-y-3"><Skeleton className="h-20 w-full rounded-md" /><Skeleton className="h-20 w-full rounded-md" /></div> : loadError ? (
          <div className="flex flex-col items-start gap-3 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
            <span className="flex items-center gap-2 text-destructive"><AlertCircle className="h-4 w-4 shrink-0" />{t("settings.contactSync.loadError")}</span>
            <Button size="sm" variant="outline" onClick={() => void load()}>{t("settings.contactSync.retryQuery")}</Button>
          </div>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">{t("settings.contactSync.empty")}</p>
        ) : <div className="divide-y divide-border">{rows.map((row) => <ChannelRow key={row.channel.id} row={row} onRetry={() => void retry(row.channel.id)} onRefresh={() => void refreshRow(row.channel.id)} onReconnect={() => addToast({ type: "info", title: t("settings.contactSync.reconnect"), description: t("settings.contactSync.reconnectHint") })} language={language} t={t} />)}</div>}
      </CardContent>
    </Card>
  )
}

function ChannelRow({ row, onRetry, onRefresh, t, onReconnect, language }: { row: Row; onRetry: () => void; onRefresh: () => void; onReconnect: () => void; language: string; t: (key: string, params?: Record<string, string | number>) => string }) {
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
    </div>
  </div>
}

function SyncStatus({ sync, language, t }: { sync?: ContactSync; language: string; t: (key: string, params?: Record<string, string | number>) => string }) {
  if (!sync) return null
  if (sync.status === "completed") return <span className="font-mono text-xs text-muted-foreground">{t("settings.contactSync.completed", { count: sync.contacts_imported })}</span>
  if (sync.status === "syncing") return <span className="flex items-center gap-1"><Clock3 className="h-4 w-4" />{t("settings.contactSync.syncing")}{sync.window_expires_at ? ` · ${t("settings.contactSync.until", { date: new Date(sync.window_expires_at).toLocaleString(language === "es" ? "es-AR" : "en-US") })}` : ""}</span>
  if (sync.status === "pending") return <span>{t("settings.contactSync.pending")}</span>
  if (sync.status === "failed") return <span className="text-destructive">{sync.can_retry ? (sync.error || t("settings.contactSync.failed")) : t("settings.contactSync.expired")}</span>
  return null
}
