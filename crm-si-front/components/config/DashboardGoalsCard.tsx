"use client"

import { useEffect, useState, type FormEvent } from "react"
import { Loader2, Target } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useToast } from "@/components/Toast"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import { getDashboardGoals, updateDashboardGoals, type DashboardGoals } from "@/lib/api/dashboard"
import { parseCurrencyInput } from "@/lib/currency"

type Form = { revenue: string; response: string; cycle: string }

const EMPTY_FORM: Form = { revenue: "", response: "", cycle: "" }

const toForm = (goals: DashboardGoals): Form => ({
  revenue: goals.revenue_monthly != null ? new Intl.NumberFormat("es-AR").format(goals.revenue_monthly) : "",
  response: goals.response_time_minutes?.toString() ?? "",
  cycle: goals.cycle_time_days?.toString() ?? "",
})

/** Vacío = sin meta. El backend valida rangos (1 a 10.080 min, 1 a 365 días). */
const toGoal = (raw: string): number | null => (raw.trim() === "" ? null : Number(raw))

export function DashboardGoalsCard() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [form, setForm] = useState<Form>(EMPTY_FORM)
  const [saved, setSaved] = useState<Form>(EMPTY_FORM)
  const [loading, setLoading] = useState(true)
  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadFailed(false)
    getDashboardGoals()
      .then((goals) => {
        if (cancelled) return
        setForm(toForm(goals))
        setSaved(toForm(goals))
      })
      .catch(() => { if (!cancelled) setLoadFailed(true) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [reloadKey])

  const dirty = form.revenue !== saved.revenue || form.response !== saved.response || form.cycle !== saved.cycle

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      const goals = await updateDashboardGoals({
        revenue_monthly: parseCurrencyInput(form.revenue),
        response_time_minutes: toGoal(form.response),
        cycle_time_days: toGoal(form.cycle),
      })
      setForm(toForm(goals))
      setSaved(toForm(goals))
      addToast({ type: "success", title: t("settings.goals.saved") })
    } catch (error) {
      addToast({ type: "error", title: t("settings.goals.saveError"), description: error instanceof Error ? error.message : undefined })
    } finally {
      setSaving(false)
    }
  }

  return (
      <SettingsBlock title={t("settings.goals.title")} description={t("settings.goals.description")} icon={Target} measure="prose">
        {loading ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Skeleton className="h-16 w-full sm:col-span-2" />
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        ) : loadFailed ? (
          <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
            {t("settings.goals.loadError")}
            <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>{t("settings.goals.retry")}</Button>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1.5 sm:col-span-2">
                <Label htmlFor="goal-revenue">{t("settings.goals.revenueLabel")}</Label>
                <div className="flex items-center gap-2">
                  <span className="text-sm text-muted-foreground" aria-hidden="true">$</span>
                  <Input
                    id="goal-revenue"
                    inputMode="decimal"
                    value={form.revenue}
                    onChange={(event) => setForm((current) => ({ ...current, revenue: event.target.value }))}
                    placeholder={t("settings.goals.none")}
                    aria-describedby="goal-revenue-hint"
                    className="w-40 tabular-nums"
                  />
                  <span className="text-sm text-muted-foreground">{t("settings.goals.perMonth")}</span>
                </div>
                <p id="goal-revenue-hint" className="text-xs text-muted-foreground">{t("settings.goals.revenueHint")}</p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="goal-response">{t("settings.goals.responseLabel")}</Label>
                <div className="flex items-center gap-2">
                  <Input
                    id="goal-response"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={10080}
                    step={1}
                    value={form.response}
                    onChange={(event) => setForm((current) => ({ ...current, response: event.target.value }))}
                    placeholder={t("settings.goals.none")}
                    aria-describedby="goal-response-hint"
                    className="w-28"
                  />
                  <span className="text-sm text-muted-foreground">{t("settings.goals.minutesUnit")}</span>
                </div>
                <p id="goal-response-hint" className="text-xs text-muted-foreground">{t("settings.goals.responseHint")}</p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="goal-cycle">{t("settings.goals.cycleLabel")}</Label>
                <div className="flex items-center gap-2">
                  <Input
                    id="goal-cycle"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={365}
                    step={1}
                    value={form.cycle}
                    onChange={(event) => setForm((current) => ({ ...current, cycle: event.target.value }))}
                    placeholder={t("settings.goals.none")}
                    aria-describedby="goal-cycle-hint"
                    className="w-28"
                  />
                  <span className="text-sm text-muted-foreground">{t("settings.goals.daysUnit")}</span>
                </div>
                <p id="goal-cycle-hint" className="text-xs text-muted-foreground">{t("settings.goals.cycleHint")}</p>
              </div>
            </div>
            <div className="flex justify-end">
              <Button type="submit" size="sm" disabled={!dirty || saving}>
                {saving && <Loader2 className="mr-2 size-4 animate-spin" aria-hidden="true" />}
                {t("settings.goals.save")}
              </Button>
            </div>
          </form>
        )}
      </SettingsBlock>
  )
}
