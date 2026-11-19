"use client"

import { useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { Loader2, PlugZap } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { isAdminRole } from "@/lib/permissions"
import {
  getMercadoPagoConfig,
  testMercadoPagoConfig,
  updateMercadoPagoConfig,
  type MercadoPagoConfig,
} from "@/lib/api/mercadopago"

const STATEMENT_DESCRIPTOR_MAX = 22

export function MercadoPagoSettings() {
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
  const [accessToken, setAccessToken] = useState("")
  const [statementDescriptor, setStatementDescriptor] = useState("")
  const [config, setConfig] = useState<MercadoPagoConfig | null>(null)

  useEffect(() => {
    const loadConfig = async () => {
      setLoading(true)
      const loaded = await getMercadoPagoConfig()
      if (loaded) {
        setConfig(loaded)
        setStatementDescriptor(loaded.statement_descriptor ?? "")
      }
      setLoading(false)
    }
    void loadConfig()
  }, [])

  const hasCredentials = config?.has_credentials ?? false

  const handleSave = async () => {
    setSaving(true)
    const result = await updateMercadoPagoConfig({
      // Write-only: solo se manda si el usuario cargó uno nuevo.
      ...(accessToken.trim() ? { access_token: accessToken.trim() } : {}),
      statement_descriptor: statementDescriptor.trim() || null,
    })
    setSaving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    if (result.data) setConfig(result.data)
    setAccessToken("")
    addToast({ type: "success", title: t("settings.mercadopago.saved") })
  }

  const handleTest = async () => {
    setTesting(true)
    const result = await testMercadoPagoConfig(accessToken.trim() || undefined)
    setTesting(false)

    if (!result.ok) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: result.error ?? t("settings.mercadopago.testError"),
      })
      return
    }

    addToast({
      type: "success",
      title: result.nickname
        ? t("settings.mercadopago.testOkAccount", { nickname: result.nickname })
        : t("settings.mercadopago.testOk"),
    })
  }

  if (!isAdmin) {
    return null
  }

  return (
    <div className="space-y-4">
      {loading ? (
        <div className="space-y-4">
          <Skeleton className="h-16 w-full rounded-md" />
          <Skeleton className="h-16 w-full rounded-md" />
        </div>
      ) : (
        <>
          <div className="space-y-2">
            <Label>{t("settings.mercadopago.accessToken")}</Label>
            <Input
              type="password"
              value={accessToken}
              onChange={(e) => setAccessToken(e.target.value)}
              placeholder={
                hasCredentials
                  ? t("settings.mercadopago.credentialSet")
                  : "APP_USR-..."
              }
              autoComplete="off"
            />
            <p className="text-xs text-muted-foreground">
              {t("settings.mercadopago.accessTokenHint")}
            </p>
          </div>

          <div className="space-y-2">
            <Label>{t("settings.mercadopago.statementDescriptor")}</Label>
            <Input
              value={statementDescriptor}
              maxLength={STATEMENT_DESCRIPTOR_MAX}
              onChange={(e) => setStatementDescriptor(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              {t("settings.mercadopago.statementDescriptorHint")}
            </p>
          </div>

          {config?.last_verified_at && (
            <p className="text-xs text-muted-foreground">
              {t("settings.mercadopago.lastVerified", {
                date: new Date(config.last_verified_at).toLocaleString(),
              })}
            </p>
          )}

          <div className="flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={handleTest}
              disabled={testing || saving || (!hasCredentials && !accessToken.trim())}
            >
              {testing ? (
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
              ) : (
                <PlugZap className="w-4 h-4 mr-2" />
              )}
              {testing ? t("settings.mercadopago.testing") : t("settings.mercadopago.test")}
            </Button>
            <Button onClick={handleSave} disabled={saving || testing}>
              {saving && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
              {t("common.save")}
            </Button>
          </div>
        </>
      )}
    </div>
  )
}
