"use client"

import { useMemo, useState } from "react"
import { Loader2, RotateCcw, Tags } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/Toast"
import {
  NAVIGATION_ITEMS,
  type NavigationKey,
  type NavigationLabels,
  resolveNavigationLabel,
} from "@/data/navigation"
import { updateNavigationLabels } from "@/lib/api/navigation-labels"
import { useAuthStore } from "@/store/useAuthStore"
import { useTranslation } from "@/hooks/useTranslation"

const MAX_LABEL_LENGTH = 30

export function NavigationLabelsCard() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const tenant = useAuthStore((state) => state.user?.tenant)
  const updateUser = useAuthStore((state) => state.updateUser)
  const [labels, setLabels] = useState<NavigationLabels>(() => tenant?.navigation_labels ?? {})
  const [saving, setSaving] = useState(false)

  const hasChanges = useMemo(() => {
    const current = tenant?.navigation_labels ?? {}
    return JSON.stringify(labels) !== JSON.stringify(current)
  }, [labels, tenant?.navigation_labels])

  const setLabel = (key: NavigationKey, value: string) => {
    setLabels((current) => ({ ...current, [key]: value }))
  }

  const resetLabel = (key: NavigationKey) => {
    setLabels((current) => {
      const next = { ...current }
      delete next[key]
      return next
    })
  }

  const resetAll = () => {
    if (Object.keys(labels).length === 0) return
    if (window.confirm(t("settings.navigationLabels.resetAllConfirm"))) setLabels({})
  }

  const save = async () => {
    const normalized: NavigationLabels = {}
    for (const item of NAVIGATION_ITEMS) {
      const value = labels[item.key]?.trim()
      if (!value) continue
      if (value.length > MAX_LABEL_LENGTH) {
        addToast({ type: "error", title: t("common.error"), description: t("settings.navigationLabels.tooLong") })
        return
      }
      normalized[item.key] = value
    }

    setSaving(true)
    const result = await updateNavigationLabels(normalized)
    setSaving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    setLabels(result.labels ?? {})
    if (tenant) updateUser({ tenant: { ...tenant, navigation_labels: result.labels ?? {} } })
    addToast({ type: "success", title: t("settings.navigationLabels.saved") })
  }

  return (
    <SettingsBlock
      title={t("settings.navigationLabels.title")}
      description={t("settings.navigationLabels.description")}
      icon={Tags}
      measure="prose"
      action={
        <Button size="sm" onClick={save} disabled={!hasChanges || saving}>
          {saving ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
          {saving ? t("common.saving") : t("common.save")}
        </Button>
      }
    >
      {/* Una fila por ítem: el nombre por defecto ya está en la etiqueta y en el placeholder, así que no repetimos "Predeterminado" debajo. */}
      <div className="divide-y divide-border border-y border-border">
        {NAVIGATION_ITEMS.map((item) => {
          const override = labels[item.key] ?? ""
          const defaultLabel = resolveNavigationLabel(item, undefined, t)
          const inputId = `navigation-label-${item.key}`
          return (
            <div
              key={item.key}
              className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2 gap-y-1.5 py-2.5 sm:grid-cols-[10rem_minmax(0,1fr)_auto]"
            >
              <Label htmlFor={inputId} className="col-span-2 flex min-w-0 items-center gap-2 sm:col-span-1">
                <item.icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span className="truncate">{defaultLabel}</span>
              </Label>
              <div className="relative min-w-0">
                <Input
                  id={inputId}
                  value={override}
                  onChange={(event) => setLabel(item.key, event.target.value)}
                  maxLength={MAX_LABEL_LENGTH}
                  placeholder={defaultLabel}
                  aria-describedby={`${inputId}-hint`}
                  className={override ? "pr-14" : undefined}
                />
                <span
                  id={`${inputId}-hint`}
                  className={
                    override
                      ? "pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs tabular-nums text-muted-foreground"
                      : "sr-only"
                  }
                >
                  {override ? `${override.length}/${MAX_LABEL_LENGTH}` : t("settings.navigationLabels.defaultHint", { label: defaultLabel })}
                </span>
              </div>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                onClick={() => resetLabel(item.key)}
                disabled={!override}
                title={t("settings.navigationLabels.reset")}
                aria-label={t("settings.navigationLabels.reset")}
                className={override ? "text-muted-foreground" : "invisible"}
              >
                <RotateCcw className="size-4" aria-hidden="true" />
              </Button>
            </div>
          )
        })}
      </div>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="text-pretty text-xs leading-5 text-muted-foreground">{t("settings.navigationLabels.mobileHint")}</p>
        <Button type="button" variant="outline" size="sm" onClick={resetAll} disabled={Object.keys(labels).length === 0 || saving}>
          <RotateCcw className="mr-1.5 size-3.5" />
          {t("settings.navigationLabels.resetAll")}
        </Button>
      </div>
    </SettingsBlock>
  )
}
