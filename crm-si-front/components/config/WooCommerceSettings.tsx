"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import { CheckCircle2, CircleAlert, Loader2, PlugZap, RefreshCw, ShoppingCart } from "lucide-react"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { isAdminRole } from "@/lib/permissions"
import { useProductFieldsStore } from "@/store/useProductFieldsStore"
import { getChannels } from "@/lib/api/channels"
import { getTemplates } from "@/lib/api/templates"
import {
  getWooConfig,
  updateWooConfig,
  testWooConfig,
  syncWooProducts,
  installWhatsAppCheckout,
  type WhatsAppCheckoutInstallResult,
  type WooConfig,
  type WooCheckoutMissing,
  type WooRetailerIdFormat,
  type WooSyncState,
} from "@/lib/api/woocommerce"

const SYNC_POLL_MS = 2000
const NO_TEMPLATE = "none"
const UNAVAILABLE_TEMPLATE = "unavailable"

const MISSING_LABEL_KEYS: Record<WooCheckoutMissing, string> = {
  woo_credentials: "settings.woocommerce.checkout.missing.wooCredentials",
  mercado_pago: "settings.woocommerce.checkout.missing.mercadoPago",
  meta_catalog_id: "settings.woocommerce.checkout.missing.metaCatalogId",
  flow_installed: "settings.woocommerce.checkout.missing.flowInstalled",
}

interface TemplateOption {
  id: number
  label: string
}

export function WooCommerceSettings() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const role = useAuthStore((state) => state.role)
  const permissions = useAuthStore((state) => state.permissions)
  const isAdmin = useMemo(
    () => isAdminRole(role, permissions),
    [role, permissions],
  )

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [requestingSync, setRequestingSync] = useState(false)
  const [sync, setSync] = useState<WooSyncState | null>(null)
  const invalidateProductFields = useProductFieldsStore((state) => state.invalidate)

  const [storeUrl, setStoreUrl] = useState("")
  const [enabled, setEnabled] = useState(false)
  const [consumerKey, setConsumerKey] = useState("")
  const [consumerSecret, setConsumerSecret] = useState("")
  const [hasCredentials, setHasCredentials] = useState(false)
  const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null)

  const [checkoutEnabled, setCheckoutEnabled] = useState(false)
  const [metaCatalogId, setMetaCatalogId] = useState("")
  const [retailerIdFormat, setRetailerIdFormat] = useState<WooRetailerIdFormat>("product_id")
  const [trackingMetaKey, setTrackingMetaKey] = useState("")
  const [paidTemplateId, setPaidTemplateId] = useState<number | null>(null)
  const [shippedTemplateId, setShippedTemplateId] = useState<number | null>(null)
  // Lo guardado en el back: el estado "listo" y el envío de plantillas se comparan contra esto.
  const [savedCheckout, setSavedCheckout] = useState<{
    enabled: boolean
    paidTemplateId: number | null
    shippedTemplateId: number | null
  } | null>(null)
  // null hasta que carga la config: sin datos no se puede afirmar que está todo listo.
  const [checkoutMissing, setCheckoutMissing] = useState<WooCheckoutMissing[] | null>(null)
  const [templateOptions, setTemplateOptions] = useState<TemplateOption[]>([])
  const [phoneByConfigId, setPhoneByConfigId] = useState<Record<number, string>>({})
  const [installing, setInstalling] = useState(false)
  const [installResults, setInstallResults] = useState<WhatsAppCheckoutInstallResult[] | null>(null)

  const applyConfig = useCallback((config: WooConfig) => {
    setStoreUrl(config.store_url ?? "")
    setEnabled(config.enabled)
    setHasCredentials(config.has_credentials)
    setLastSyncedAt(config.last_synced_at)
    setSync(config.sync)
    setCheckoutEnabled(config.whatsapp_checkout_enabled ?? false)
    setMetaCatalogId(config.meta_catalog_id ?? "")
    setRetailerIdFormat(config.catalog_retailer_id_format ?? "product_id")
    setTrackingMetaKey(config.tracking_meta_key ?? "")
    setPaidTemplateId(config.paid_template_id ?? null)
    setShippedTemplateId(config.shipped_template_id ?? null)
    setSavedCheckout({
      enabled: config.whatsapp_checkout_enabled ?? false,
      paidTemplateId: config.paid_template_id ?? null,
      shippedTemplateId: config.shipped_template_id ?? null,
    })
    setCheckoutMissing(config.checkout_missing ?? [])
  }, [])

  useEffect(() => {
    const loadConfig = async () => {
      setLoading(true)
      const config = await getWooConfig()
      if (config) applyConfig(config)
      setLoading(false)
    }
    void loadConfig()
  }, [applyConfig])

  // Plantillas aprobadas de todos los números de WhatsApp, para los avisos
  // fuera de la ventana de 24 h.
  useEffect(() => {
    if (!isAdmin) return
    let cancelled = false

    const loadTemplates = async () => {
      try {
        const channels = (await getChannels()).filter((c) => c.whatsapp_config)
        const phones: Record<number, string> = {}
        const lists = await Promise.all(
          channels.map(async (channel) => {
            const config = channel.whatsapp_config!
            phones[config.id] = config.display_phone_number || channel.name
            const templates = await getTemplates(channel.id).catch(() => [])
            return templates
              .filter((tpl) => tpl.status === "APPROVED")
              .map((tpl) => ({
                id: tpl.id,
                label: channels.length > 1
                  ? `${tpl.name} (${tpl.language}) · ${channel.name}`
                  : `${tpl.name} (${tpl.language})`,
              }))
          }),
        )
        if (cancelled) return
        setPhoneByConfigId(phones)
        setTemplateOptions(lists.flat())
      } catch {
        // Sin plantillas: los selects quedan solo con "Sin plantilla".
      }
    }

    void loadTemplates()
    return () => {
      cancelled = true
    }
  }, [isAdmin])

  // Una corrida "stale" no avanza hace rato (worker caído o job perdido): se
  // deja de consultar y se habilita reiniciarla.
  const syncRunning = sync?.status === "running" && !sync.stale

  // El sync corre en cola: mientras esté en curso se consulta su progreso.
  // Timeout encadenado para no superponer requests lentos.
  useEffect(() => {
    if (!syncRunning) return

    let cancelled = false
    let timer = 0

    const poll = async () => {
      try {
        const config = await getWooConfig()
        if (cancelled) return
        if (config?.sync) {
          setSync(config.sync)
          setLastSyncedAt(config.last_synced_at)
          if (config.sync.status === "completed") {
            // El sync puede crear campos del catálogo (SKU, Stock…).
            invalidateProductFields()
            addToast({ type: "success", title: t("settings.woocommerce.syncOk") })
          } else if (config.sync.status === "failed") {
            addToast({
              type: "error",
              title: t("settings.woocommerce.syncFailed"),
              description: config.sync.error ?? undefined,
            })
          }
          if (config.sync.status !== "running" || config.sync.stale) return
        }
      } catch {
        // Error de red puntual: se reintenta en el próximo ciclo.
        if (cancelled) return
      }
      timer = window.setTimeout(poll, SYNC_POLL_MS)
    }

    timer = window.setTimeout(poll, SYNC_POLL_MS)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [syncRunning, invalidateProductFields, addToast, t])

  const handleSave = async () => {
    setSaving(true)

    const result = await updateWooConfig({
      store_url: storeUrl.trim(),
      enabled,
      // Write-only: solo se mandan si el usuario cargó unas nuevas.
      ...(consumerKey.trim() ? { consumer_key: consumerKey.trim() } : {}),
      ...(consumerSecret.trim()
        ? { consumer_secret: consumerSecret.trim() }
        : {}),
      whatsapp_checkout_enabled: checkoutEnabled,
      meta_catalog_id: metaCatalogId.trim() || null,
      catalog_retailer_id_format: retailerIdFormat,
      tracking_meta_key: trackingMetaKey.trim() || null,
      // Solo si cambiaron: una plantilla guardada que dejó de estar aprobada no
      // debe impedir guardar la URL o las credenciales.
      ...(paidTemplateId !== savedCheckout?.paidTemplateId ? { paid_template_id: paidTemplateId } : {}),
      ...(shippedTemplateId !== savedCheckout?.shippedTemplateId ? { shipped_template_id: shippedTemplateId } : {}),
    })

    setSaving(false)

    if (result.error) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: result.error,
      })
      return
    }

    if (result.data) {
      setHasCredentials(result.data.has_credentials)
      setSavedCheckout({
        enabled: result.data.whatsapp_checkout_enabled ?? false,
        paidTemplateId: result.data.paid_template_id ?? null,
        shippedTemplateId: result.data.shipped_template_id ?? null,
      })
      setCheckoutMissing(result.data.checkout_missing ?? [])
    }
    setConsumerKey("")
    setConsumerSecret("")

    addToast({ type: "success", title: t("settings.woocommerce.saved") })
  }

  const handleTest = async () => {
    setTesting(true)

    const result = await testWooConfig({
      store_url: storeUrl.trim(),
      ...(consumerKey.trim() ? { consumer_key: consumerKey.trim() } : {}),
      ...(consumerSecret.trim()
        ? { consumer_secret: consumerSecret.trim() }
        : {}),
    })

    setTesting(false)

    if (!result.ok) {
      const map: Record<string, string> = {
        invalid_credentials: "settings.woocommerce.testErrorCredentials",
        unreachable: "settings.woocommerce.testErrorUnreachable",
      }
      const key = map[result.error_code ?? ""]
      addToast({
        type: "error",
        title: t("common.error"),
        // invalid_url trae un mensaje propio del back; el resto usa i18n.
        description: key
          ? t(key)
          : result.error_message ?? t("settings.woocommerce.testErrorUnknown"),
      })
      return
    }

    addToast({
      type: "success",
      title: t("settings.woocommerce.testOk"),
    })
  }

  const handleSync = async () => {
    setRequestingSync(true)
    const result = await syncWooProducts()
    setRequestingSync(false)

    if (result.error) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: result.error,
      })
      return
    }

    if (result.data) setSync(result.data.sync)
  }

  const handleInstall = async () => {
    setInstalling(true)
    const result = await installWhatsAppCheckout()
    setInstalling(false)

    if (result.error) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: result.error,
      })
      return
    }

    const results = result.data ?? []
    setInstallResults(results)
    if (results.some((r) => r.commerce_error)) {
      addToast({
        type: "warning",
        title: t("settings.woocommerce.checkout.installedWithWarnings"),
        description: t("settings.woocommerce.checkout.commerceErrorHint"),
      })
    } else {
      addToast({ type: "success", title: t("settings.woocommerce.checkout.installed") })
    }
    const config = await getWooConfig()
    if (config) setCheckoutMissing(config.checkout_missing ?? [])
  }

  const templateSelect = (
    value: number | null,
    onChange: (value: number | null) => void,
  ) => {
    // La guardada puede no estar en la lista (pausada, rechazada, borrada o
    // sin cargar): se muestra como no disponible en vez de un select vacío.
    const missingFromList = value !== null && !templateOptions.some((option) => option.id === value)

    return (
      <Select
        value={value === null ? NO_TEMPLATE : missingFromList ? UNAVAILABLE_TEMPLATE : String(value)}
        onValueChange={(v) => {
          if (v === UNAVAILABLE_TEMPLATE) return
          onChange(v === NO_TEMPLATE ? null : Number(v))
        }}
      >
        <SelectTrigger className="w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NO_TEMPLATE}>{t("settings.woocommerce.checkout.noTemplate")}</SelectItem>
          {missingFromList && (
            <SelectItem value={UNAVAILABLE_TEMPLATE} disabled>
              {t("settings.woocommerce.checkout.templateUnavailable")}
            </SelectItem>
          )}
          {templateOptions.map((option) => (
            <SelectItem key={option.id} value={String(option.id)}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  if (!isAdmin) {
    return null
  }

  const canSync = hasCredentials && !!storeUrl.trim()
  const syncing = requestingSync || syncRunning
  const syncResult = sync?.result

  return (
    <div className="space-y-4">
        {loading ? (
          <div className="space-y-4">
            <Skeleton className="h-12 w-full rounded-lg" />
            <Skeleton className="h-16 w-full rounded-md" />
            <Skeleton className="h-16 w-full rounded-md" />
            <Skeleton className="h-16 w-full rounded-md" />
          </div>
        ) : (
          <>
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label>{t("settings.woocommerce.enabled")}</Label>
                <p className="text-xs text-muted-foreground">
                  {t("settings.woocommerce.enabledHint")}
                </p>
              </div>
              <Switch checked={enabled} onCheckedChange={setEnabled} />
            </div>

            <div className="space-y-2">
              <Label>{t("settings.woocommerce.storeUrl")}</Label>
              <Input
                type="url"
                value={storeUrl}
                onChange={(e) => setStoreUrl(e.target.value)}
                placeholder="https://mitienda.com"
              />
              <p className="text-xs text-muted-foreground">
                {t("settings.woocommerce.storeUrlHint")}
              </p>
            </div>

            <div className="space-y-2">
              <Label>{t("settings.woocommerce.consumerKey")}</Label>
              <Input
                type="password"
                value={consumerKey}
                onChange={(e) => setConsumerKey(e.target.value)}
                placeholder={
                  hasCredentials
                    ? t("settings.woocommerce.credentialSet")
                    : "ck_..."
                }
                autoComplete="off"
              />
            </div>

            <div className="space-y-2">
              <Label>{t("settings.woocommerce.consumerSecret")}</Label>
              <Input
                type="password"
                value={consumerSecret}
                onChange={(e) => setConsumerSecret(e.target.value)}
                placeholder={
                  hasCredentials
                    ? t("settings.woocommerce.credentialSet")
                    : "cs_..."
                }
                autoComplete="off"
              />
              <p className="text-xs text-muted-foreground">
                {t("settings.woocommerce.credentialsHint")}
              </p>
            </div>

            {lastSyncedAt && (
              <p className="text-xs text-muted-foreground">
                {t("settings.woocommerce.lastSynced", {
                  date: new Date(lastSyncedAt).toLocaleString(),
                })}
              </p>
            )}

            {sync && (
              <div className="space-y-1 text-xs" aria-live="polite">
                {sync.status === "running" && !sync.stale && (
                  <p className="text-muted-foreground">
                    {t("settings.woocommerce.syncProgress", { total: syncResult?.total ?? 0 })}
                  </p>
                )}
                {sync.status === "running" && sync.stale && (
                  <p className="text-warning">{t("settings.woocommerce.syncStale")}</p>
                )}
                {sync.status === "completed" && syncResult && (
                  <p className="text-muted-foreground">
                    {t("settings.woocommerce.syncResult", {
                      created: syncResult.created,
                      updated: syncResult.updated,
                      deactivated: syncResult.deactivated,
                      variants: syncResult.variants,
                    })}
                  </p>
                )}
                {sync.status === "failed" && (
                  <p className="text-destructive">
                    {t("settings.woocommerce.syncFailed")}
                    {sync.error ? `: ${sync.error}` : ""}
                  </p>
                )}
                {sync.status !== "running" && syncResult && syncResult.skipped_fields.length > 0 && (
                  <p className="text-warning">
                    {t("settings.woocommerce.syncSkippedFields", {
                      fields: syncResult.skipped_fields.join(", "),
                    })}
                  </p>
                )}
              </div>
            )}

            <div className="flex flex-wrap justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={handleSync}
                disabled={syncing || saving || testing || !canSync}
              >
                {syncing ? (
                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                ) : (
                  <RefreshCw className="w-4 h-4 mr-2" />
                )}
                {syncing
                  ? t("settings.woocommerce.syncing")
                  : t("settings.woocommerce.sync")}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={handleTest}
                disabled={
                  testing ||
                  saving ||
                  !storeUrl.trim() ||
                  (!hasCredentials &&
                    (!consumerKey.trim() || !consumerSecret.trim()))
                }
              >
                {testing ? (
                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                ) : (
                  <PlugZap className="w-4 h-4 mr-2" />
                )}
                {testing
                  ? t("settings.woocommerce.testing")
                  : t("settings.woocommerce.test")}
              </Button>
              <Button onClick={handleSave} disabled={saving || testing}>
                {saving && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
                {t("common.save")}
              </Button>
            </div>

            <div className="space-y-4 border-t pt-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="flex items-center gap-2">
                    <ShoppingCart className="h-4 w-4" />
                    {t("settings.woocommerce.checkout.title")}
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    {t("settings.woocommerce.checkout.hint")}
                  </p>
                </div>
                <Switch checked={checkoutEnabled} onCheckedChange={setCheckoutEnabled} />
              </div>

              <div className="space-y-2">
                <Label>{t("settings.woocommerce.checkout.metaCatalogId")}</Label>
                <Input
                  value={metaCatalogId}
                  onChange={(e) => setMetaCatalogId(e.target.value)}
                  placeholder="123456789012345"
                />
              </div>

              <div className="space-y-2">
                <Label>{t("settings.woocommerce.checkout.retailerIdFormat")}</Label>
                <Select
                  value={retailerIdFormat}
                  onValueChange={(v) => setRetailerIdFormat(v as WooRetailerIdFormat)}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="product_id">
                      {t("settings.woocommerce.checkout.retailerIdProductId")}
                    </SelectItem>
                    <SelectItem value="sku">
                      {t("settings.woocommerce.checkout.retailerIdSku")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>{t("settings.woocommerce.checkout.trackingMetaKey")}</Label>
                <Input
                  value={trackingMetaKey}
                  onChange={(e) => setTrackingMetaKey(e.target.value)}
                  placeholder="_tracking_number"
                />
              </div>

              <div className="space-y-2">
                <Label>{t("settings.woocommerce.checkout.paidTemplate")}</Label>
                {templateSelect(paidTemplateId, setPaidTemplateId)}
                <p className="text-xs text-muted-foreground">
                  {t("settings.woocommerce.checkout.templateVariables", { vars: "{{pedido}}, {{total}}" })}
                </p>
              </div>

              <div className="space-y-2">
                <Label>{t("settings.woocommerce.checkout.shippedTemplate")}</Label>
                {templateSelect(shippedTemplateId, setShippedTemplateId)}
                <p className="text-xs text-muted-foreground">
                  {t("settings.woocommerce.checkout.templateVariables", { vars: "{{pedido}}, {{seguimiento}}" })}
                </p>
              </div>

              {checkoutMissing !== null && savedCheckout !== null && (
                checkoutMissing.length > 0 ? (
                  <div className="space-y-1 text-xs">
                    <p className="font-medium">{t("settings.woocommerce.checkout.missingTitle")}</p>
                    <ul className="space-y-1">
                      {checkoutMissing.map((code) => (
                        <li key={code} className="flex items-center gap-2 text-warning">
                          <CircleAlert className="h-3.5 w-3.5" />
                          {t(MISSING_LABEL_KEYS[code] ?? code)}
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : savedCheckout.enabled ? (
                  <p className="flex items-center gap-2 text-xs text-success">
                    <CheckCircle2 className="h-3.5 w-3.5" />
                    {t("settings.woocommerce.checkout.ready")}
                  </p>
                ) : (
                  <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <CircleAlert className="h-3.5 w-3.5" />
                    {t("settings.woocommerce.checkout.disabled")}
                  </p>
                )
              )}

              {installResults && (
                <ul className="space-y-1 text-xs text-muted-foreground" aria-live="polite">
                  {installResults.length === 0 && (
                    <li>{t("settings.woocommerce.checkout.installNoNumbers")}</li>
                  )}
                  {installResults.map((r) => (
                    <li key={r.whatsapp_config_id}>
                      {t("settings.woocommerce.checkout.installRow", {
                        phone: phoneByConfigId[r.whatsapp_config_id] ?? `#${r.whatsapp_config_id}`,
                        status: r.status,
                      })}
                      {r.commerce_error && (
                        <span className="block text-warning">
                          {t("settings.woocommerce.checkout.commerceError", { error: r.commerce_error })}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              <div className="flex flex-wrap justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleInstall}
                  disabled={installing || saving}
                >
                  {installing && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
                  {t("settings.woocommerce.checkout.install")}
                </Button>
              </div>
            </div>
          </>
        )}
    </div>
  )
}
