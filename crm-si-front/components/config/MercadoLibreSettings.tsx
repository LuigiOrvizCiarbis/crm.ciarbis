"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { ExternalLink, Loader2, RefreshCw } from "lucide-react"
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
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { Switch } from "@/components/ui/switch"
import { useToast } from "@/components/Toast"
import { MercadoLibreProductDialog } from "@/components/products/MercadoLibreProductDialog"
import { useTranslation } from "@/hooks/useTranslation"
import { isAdminRole } from "@/lib/permissions"
import { useAuthStore } from "@/store/useAuthStore"
import {
  disconnectMercadoLibre,
  getMercadoLibreAuthorizationUrl,
  getMercadoLibreConnection,
  getMercadoLibreListings,
  syncMercadoLibre,
  updateMercadoLibreConnection,
  type MercadoLibreCondition,
  type MercadoLibreConnection,
  type MercadoLibreListing,
  type MercadoLibreListingType,
  type MercadoLibreSummary,
} from "@/lib/api/mercadolibre"

const LISTING_TYPES: MercadoLibreListingType[] = ["gold_special", "gold_pro", "free"]
const CONDITIONS: MercadoLibreCondition[] = ["new", "used"]
const SUMMARY_KEYS: (keyof MercadoLibreSummary)[] = ["synced", "pending", "paused", "needs_attention"]
const POLL_INTERVAL_MS = 5000
const QUANTITY_MIN = 1
const QUANTITY_MAX = 9999

interface SettingsForm {
  listing_type_id: MercadoLibreListingType
  condition: MercadoLibreCondition
  default_quantity: string
  sync_enabled: boolean
}

function formFrom(connection: MercadoLibreConnection): SettingsForm {
  return {
    listing_type_id: connection.listing_type_id,
    condition: connection.condition,
    default_quantity: String(connection.default_quantity),
    sync_enabled: connection.sync_enabled,
  }
}

export function MercadoLibreSettings() {
  const { addToast } = useToast()
  const { t, language } = useTranslation()
  const role = useAuthStore((state) => state.role)
  const permissions = useAuthStore((state) => state.permissions)
  const isAdmin = useMemo(() => isAdminRole(role, permissions), [role, permissions])

  const [loading, setLoading] = useState(true)
  const [connection, setConnection] = useState<MercadoLibreConnection | null>(null)
  const [form, setForm] = useState<SettingsForm | null>(null)
  const [connecting, setConnecting] = useState(false)
  const [disconnectOpen, setDisconnectOpen] = useState(false)
  const [disconnecting, setDisconnecting] = useState(false)
  const [saving, setSaving] = useState(false)
  const [syncing, setSyncing] = useState(false)

  useEffect(() => {
    const load = async () => {
      setLoading(true)
      const data = await getMercadoLibreConnection()
      setConnection(data)
      if (data) setForm(formFrom(data))
      setLoading(false)
    }
    void load()
  }, [])

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const result = params.get("mercadolibre")
    if (!result) return

    if (result === "connected") {
      addToast({ type: "success", title: t("settings.mercadolibre.connected") })
    } else {
      const descriptionKey =
        result === "expired"
          ? "settings.mercadolibre.stateExpired"
          : result === "denied"
            ? "settings.mercadolibre.denied"
            : "settings.mercadolibre.connectError"
      addToast({ type: "error", title: t("common.error"), description: t(descriptionKey) })
    }

    params.delete("mercadolibre")
    const newSearch = params.toString()
    window.history.replaceState(
      null,
      "",
      window.location.pathname + (newSearch ? `?${newSearch}` : "") + window.location.hash,
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // El polling solo refresca estado y contadores: no pisa lo que el usuario está editando.
  const refreshConnection = useCallback(async () => {
    const data = await getMercadoLibreConnection()
    if (data) setConnection(data)
  }, [])

  const hasPending = (connection?.summary.pending ?? 0) > 0
  useEffect(() => {
    if (!hasPending) return
    const timer = setInterval(() => void refreshConnection(), POLL_INTERVAL_MS)
    return () => clearInterval(timer)
  }, [hasPending, refreshConnection])

  const handleConnect = async () => {
    setConnecting(true)
    const result = await getMercadoLibreAuthorizationUrl()
    setConnecting(false)

    if (result.error || !result.data) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: result.error ?? t("settings.mercadolibre.connectError"),
      })
      return
    }

    window.location.href = result.data
  }

  const handleDisconnect = async () => {
    setDisconnecting(true)
    const result = await disconnectMercadoLibre()
    setDisconnecting(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    setDisconnectOpen(false)
    addToast({ type: "success", title: t("settings.mercadolibre.disconnected") })
    await refreshConnection()
  }

  const handleSave = async () => {
    if (!form) return
    const quantity = Number(form.default_quantity)
    if (!Number.isInteger(quantity) || quantity < QUANTITY_MIN || quantity > QUANTITY_MAX) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: t("settings.mercadolibre.defaultQuantityInvalid"),
      })
      return
    }

    setSaving(true)
    const result = await updateMercadoLibreConnection({
      listing_type_id: form.listing_type_id,
      condition: form.condition,
      default_quantity: quantity,
      sync_enabled: form.sync_enabled,
    })
    setSaving(false)

    if (result.error || !result.data) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    setConnection(result.data)
    setForm(formFrom(result.data))
    addToast({ type: "success", title: t("settings.mercadolibre.saved") })
  }

  const handleSync = async () => {
    setSyncing(true)
    const result = await syncMercadoLibre()
    setSyncing(false)

    if (result.error || !result.data) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    setConnection(result.data)
    addToast({ type: "success", title: t("settings.mercadolibre.syncStarted") })
  }

  const formatDate = (iso: string) =>
    new Intl.DateTimeFormat(language === "es" ? "es-AR" : "en-US", {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(iso))

  if (!isAdmin) return null

  if (loading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-16 w-full rounded-md" />
        <Skeleton className="h-16 w-full rounded-md" />
      </div>
    )
  }

  if (!connection) {
    return <p className="text-sm text-destructive">{t("settings.mercadolibre.loadError")}</p>
  }

  const isConnected = connection.status === "connected"
  const needsReauth = connection.status === "needs_reauth"

  if (!isConnected && !needsReauth) {
    return (
      <div className="space-y-4 rounded-md border border-border bg-muted/30 p-4">
        <p className="text-sm leading-6 text-muted-foreground">
          {t("settings.mercadolibre.notConnectedHint")}
        </p>
        {!connection.configured && (
          <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-foreground">
            {t("settings.mercadolibre.notConfigured")}
          </p>
        )}
        <Button size="sm" onClick={handleConnect} disabled={connecting || !connection.configured}>
          {connecting && <Loader2 className="mr-2 size-4 animate-spin" />}
          {t("settings.mercadolibre.connect")}
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="max-w-[62ch] text-sm">
          <p className="font-medium text-foreground">
            {connection.nickname ?? "—"}
            {connection.site_id && (
              <span className="ml-2 text-muted-foreground">
                {t("settings.mercadolibre.site", { site: connection.site_id })}
              </span>
            )}
          </p>
          {connection.connected_at && (
            <p className="mt-1 text-muted-foreground">
              {t("settings.mercadolibre.connectedSince", { date: formatDate(connection.connected_at) })}
            </p>
          )}
          {needsReauth && (
            <p className="mt-1 text-destructive">{t("settings.mercadolibre.needsReauthHint")}</p>
          )}
        </div>
        <div className="flex gap-2">
          {needsReauth && (
            <Button
              size="sm"
              onClick={handleConnect}
              disabled={connecting || !connection.configured}
            >
              {connecting && <Loader2 className="mr-2 size-4 animate-spin" />}
              {t("settings.mercadolibre.reconnect")}
            </Button>
          )}
          <Button size="sm" variant="outline" onClick={() => setDisconnectOpen(true)}>
            {t("settings.mercadolibre.disconnect")}
          </Button>
        </div>
      </div>

      {form && (
        <section className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="ml-listing-type">{t("settings.mercadolibre.listingType")}</Label>
              <Select
                value={form.listing_type_id}
                onValueChange={(value) =>
                  setForm({ ...form, listing_type_id: value as MercadoLibreListingType })
                }
              >
                <SelectTrigger id="ml-listing-type" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {LISTING_TYPES.map((type) => (
                    <SelectItem key={type} value={type}>
                      {t(`settings.mercadolibre.listingTypes.${type}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="ml-condition">{t("settings.mercadolibre.condition")}</Label>
              <Select
                value={form.condition}
                onValueChange={(value) => setForm({ ...form, condition: value as MercadoLibreCondition })}
              >
                <SelectTrigger id="ml-condition" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {CONDITIONS.map((condition) => (
                    <SelectItem key={condition} value={condition}>
                      {t(`settings.mercadolibre.conditions.${condition}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="ml-default-quantity">{t("settings.mercadolibre.defaultQuantity")}</Label>
              <Input
                id="ml-default-quantity"
                type="number"
                min={QUANTITY_MIN}
                max={QUANTITY_MAX}
                step={1}
                value={form.default_quantity}
                onChange={(event) => setForm({ ...form, default_quantity: event.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                {t("settings.mercadolibre.defaultQuantityHint")}
              </p>
            </div>
          </div>

          <div className="flex items-start justify-between gap-4 rounded-md border border-border p-3">
            <div className="space-y-0.5">
              <Label htmlFor="ml-sync-enabled">{t("settings.mercadolibre.syncEnabled")}</Label>
              <p className="text-xs text-muted-foreground">
                {t("settings.mercadolibre.syncEnabledHint")}
              </p>
            </div>
            <Switch
              id="ml-sync-enabled"
              checked={form.sync_enabled}
              onCheckedChange={(checked) => setForm({ ...form, sync_enabled: checked })}
            />
          </div>

          <div className="flex justify-end">
            <Button onClick={handleSave} disabled={saving}>
              {saving && <Loader2 className="mr-2 size-4 animate-spin" />}
              {t("common.save")}
            </Button>
          </div>
        </section>
      )}

      <section className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-sm font-semibold">{t("settings.mercadolibre.summaryTitle")}</h3>
          <Button
            size="sm"
            variant="outline"
            onClick={handleSync}
            disabled={syncing || !isConnected}
          >
            {syncing ? (
              <Loader2 className="mr-2 size-4 animate-spin" />
            ) : (
              <RefreshCw className="mr-2 size-4" />
            )}
            {t("settings.mercadolibre.syncNow")}
          </Button>
        </div>
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {SUMMARY_KEYS.map((key) => (
            <div key={key} className="rounded-md border border-border p-3">
              <dt className="text-xs text-muted-foreground">{t(`settings.mercadolibre.summary.${key}`)}</dt>
              <dd className="mt-1 text-lg font-semibold tabular-nums">{connection.summary[key]}</dd>
            </div>
          ))}
        </dl>
        <p className="flex items-center gap-2 text-xs text-muted-foreground">
          {hasPending && <Loader2 className="size-3 animate-spin" />}
          {connection.last_full_sync_at
            ? t("settings.mercadolibre.lastFullSync", { date: formatDate(connection.last_full_sync_at) })
            : t("settings.mercadolibre.neverSynced")}
        </p>
      </section>

      <AttentionList
        needsAttention={connection.summary.needs_attention}
        onChanged={refreshConnection}
      />

      <AlertDialog open={disconnectOpen} onOpenChange={(open) => !disconnecting && setDisconnectOpen(open)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("settings.mercadolibre.disconnectConfirmTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("settings.mercadolibre.disconnectConfirmBody")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={disconnecting}>{t("settings.mercadolibre.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(event) => {
                event.preventDefault()
                void handleDisconnect()
              }}
              disabled={disconnecting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {disconnecting && <Loader2 className="mr-2 size-4 animate-spin" />}
              {t("settings.mercadolibre.disconnect")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

interface AttentionListProps {
  /** Contador del resumen: cuando cambia (polling, sync) se recarga la lista. */
  needsAttention: number
  onChanged: () => Promise<void>
}

function AttentionList({ needsAttention, onChanged }: AttentionListProps) {
  const { t } = useTranslation()
  const [listings, setListings] = useState<MercadoLibreListing[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [productId, setProductId] = useState<number | null>(null)

  const loadFirstPage = useCallback(async () => {
    setLoading(true)
    const result = await getMercadoLibreListings("needs_attention", 1)
    if (result.data) {
      setListings(result.data.data)
      setPage(result.data.meta.current_page)
      setLastPage(result.data.meta.last_page)
      setError(null)
    } else {
      setError(result.error ?? t("settings.mercadolibre.listingsError"))
    }
    setLoading(false)
  }, [t])

  useEffect(() => {
    void loadFirstPage()
  }, [loadFirstPage, needsAttention])

  const loadMore = async () => {
    setLoadingMore(true)
    const result = await getMercadoLibreListings("needs_attention", page + 1)
    setLoadingMore(false)
    if (!result.data) {
      setError(result.error ?? t("settings.mercadolibre.listingsError"))
      return
    }
    const loaded = result.data
    setListings((prev) => {
      const seen = new Set(prev.map((listing) => listing.id))
      return [...prev, ...loaded.data.filter((listing) => !seen.has(listing.id))]
    })
    setPage(loaded.meta.current_page)
    setLastPage(loaded.meta.last_page)
  }

  return (
    <section className="space-y-3">
      <h3 className="text-sm font-semibold">{t("settings.mercadolibre.attentionTitle")}</h3>
      {loading ? (
        <Skeleton className="h-16 w-full rounded-md" />
      ) : error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : listings.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("settings.mercadolibre.attentionEmpty")}</p>
      ) : (
        <ul className="divide-y divide-border rounded-md border border-border">
          {listings.map((listing) => (
            <li key={listing.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
              <div className="min-w-0 flex-1 space-y-0.5">
                <p className="font-medium text-foreground">
                  {listing.product_name}
                  {listing.variant_label && (
                    <span className="ml-1 font-normal text-muted-foreground">· {listing.variant_label}</span>
                  )}
                </p>
                {listing.error ? (
                  <p className="text-xs text-destructive">{listing.error}</p>
                ) : listing.missing.length > 0 ? (
                  <p className="text-xs text-destructive">
                    {t("settings.mercadolibre.missing", {
                      fields: listing.missing.map((item) => item.name).join(", "),
                    })}
                  </p>
                ) : null}
                {listing.permalink && (
                  <a
                    href={listing.permalink}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                  >
                    {t("settings.mercadolibre.viewOnMl")}
                    <ExternalLink className="size-3" aria-hidden="true" />
                  </a>
                )}
              </div>
              <Button size="sm" variant="outline" onClick={() => setProductId(listing.product_id)}>
                {t("settings.mercadolibre.complete")}
              </Button>
            </li>
          ))}
        </ul>
      )}
      {!loading && page < lastPage && (
        <Button variant="ghost" size="sm" onClick={loadMore} disabled={loadingMore}>
          {loadingMore && <Loader2 className="mr-2 size-4 animate-spin" />}
          {t("settings.mercadolibre.loadMore")}
        </Button>
      )}

      {productId !== null && (
        <MercadoLibreProductDialog
          productId={productId}
          open
          onOpenChange={(open) => !open && setProductId(null)}
          onSaved={() => {
            void loadFirstPage()
            void onChanged()
          }}
        />
      )}
    </section>
  )
}
