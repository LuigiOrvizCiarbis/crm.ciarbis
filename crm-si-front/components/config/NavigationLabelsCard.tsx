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
      <div className="space-y-3">
        {NAVIGATION_ITEMS.map((item) => {
          const override = labels[item.key] ?? ""
          const defaultLabel = resolveNavigationLabel(item, undefined, t)
          return (
            <div key={item.key} className="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2 border-b border-border/70 pb-3 last:border-0">
              <div className="min-w-0 space-y-1.5">
                <Label htmlFor={`navigation-label-${item.key}`} className="flex items-center gap-2">
                  <span aria-hidden>{item.emoji}</span>
                  {defaultLabel}
                </Label>
                <Input
                  id={`navigation-label-${item.key}`}
                  value={override}
                  onChange={(event) => setLabel(item.key, event.target.value)}
                  maxLength={MAX_LABEL_LENGTH}
                  placeholder={defaultLabel}
                  aria-describedby={`navigation-label-${item.key}-hint`}
                />
                <p id={`navigation-label-${item.key}-hint`} className="text-xs text-muted-foreground">
                  {override ? `${override.length}/${MAX_LABEL_LENGTH}` : t("settings.navigationLabels.defaultHint", { label: defaultLabel })}
                </p>
              </div>
              <Button
                type="button"
                size="icon"
                variant="ghost"
                onClick={() => resetLabel(item.key)}
                disabled={!override}
                title={t("settings.navigationLabels.reset")}
                aria-label={t("settings.navigationLabels.reset")}
              >
                <RotateCcw className="size-4" />
              </Button>
            </div>
          )
        })}
      </div>
      <div className="mt-4 flex items-center justify-between gap-4 border-t border-border pt-4">
        <p className="text-xs leading-5 text-muted-foreground">{t("settings.navigationLabels.mobileHint")}</p>
        <Button type="button" variant="outline" size="sm" onClick={resetAll} disabled={Object.keys(labels).length === 0 || saving}>
          <RotateCcw className="mr-1.5 size-3.5" />
          {t("settings.navigationLabels.resetAll")}
        </Button>
      </div>
    </SettingsBlock>
  )
}
