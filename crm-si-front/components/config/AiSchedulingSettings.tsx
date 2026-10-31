"use client"

import { useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { AlertTriangle, CalendarClock, Loader2, Plus, X } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import {
  getAiSchedulingConfig,
  updateAiSchedulingConfig,
  type AiSchedulingCalendarUser,
  type AiSchedulingConfigInput,
  type AiSchedulingMode,
  type TimeRange,
  type Weekday,
} from "@/lib/api/ai-scheduling"

const WEEKDAYS: Weekday[] = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]
const MODES: AiSchedulingMode[] = ["auto", "approval", "criteria"]
const MODE_LABEL_KEYS: Record<AiSchedulingMode, string> = {
  auto: "settings.aiAssistant.scheduling.modeAuto",
  approval: "settings.aiAssistant.scheduling.modeApproval",
  criteria: "settings.aiAssistant.scheduling.modeCriteria",
}
const CRITERIA_MAX_LENGTH = 5000
const MAX_RANGES_PER_DAY = 3
const DEFAULT_RANGE: TimeRange = { start: "09:00", end: "18:00" }
// Valor del Select para "sin calendario por defecto" (Radix no acepta "").
const NO_DEFAULT_USER = "none"

type NumericField =
  | "meeting_duration_minutes"
  | "slot_interval_minutes"
  | "min_notice_hours"
  | "max_days_ahead"

const NUMERIC_FIELDS: { field: NumericField; labelKey: string; min: number; max: number }[] = [
  { field: "meeting_duration_minutes", labelKey: "settings.aiAssistant.scheduling.duration", min: 10, max: 480 },
  { field: "slot_interval_minutes", labelKey: "settings.aiAssistant.scheduling.interval", min: 5, max: 240 },
  { field: "min_notice_hours", labelKey: "settings.aiAssistant.scheduling.minNotice", min: 0, max: 168 },
  { field: "max_days_ahead", labelKey: "settings.aiAssistant.scheduling.maxDaysAhead", min: 1, max: 60 },
]

export function AiSchedulingSettings() {
  const { addToast } = useToast()
  const { t } = useTranslation()

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState<AiSchedulingConfigInput | null>(null)
  const [calendarUsers, setCalendarUsers] = useState<AiSchedulingCalendarUser[]>([])

  const timezones = useMemo(() => {
    try {
      return Intl.supportedValuesOf("timeZone")
    } catch {
      return []
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    getAiSchedulingConfig().then((config) => {
      if (cancelled) return
      if (config) {
        const { calendar_users, ...input } = config
        setForm(input)
        setCalendarUsers(calendar_users)
      }
      setLoading(false)
    })
    return () => {
      cancelled = true
    }
  }, [])

  if (loading) {
    return <Skeleton className="h-40 w-full rounded-lg" />
  }

  if (!form) return null

  const update = (patch: Partial<AiSchedulingConfigInput>) =>
    setForm((prev) => (prev ? { ...prev, ...patch } : prev))

  const setDayRanges = (day: Weekday, ranges: TimeRange[]) =>
    setForm((prev) =>
      prev ? { ...prev, working_hours: { ...prev.working_hours, [day]: ranges } } : prev,
    )

  const handleSave = async () => {
    setSaving(true)
    try {
      const { data, error } = await updateAiSchedulingConfig(form)
      if (error || !data) {
        throw new Error(error || "No se pudo guardar la agenda. Volvé a intentar.")
      }

      const { calendar_users, ...input } = data
      setForm(input)
      setCalendarUsers(calendar_users)
      addToast({ type: "success", title: t("settings.aiAssistant.scheduling.saved") })
    } catch (error) {
      addToast({
        type: "error",
        title: t("common.error"),
        description:
          error instanceof Error && error.message
            ? error.message
            : "No se pudo guardar la agenda. Revisá tu conexión y volvé a intentar.",
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rounded-lg border border-border bg-muted/20">
      <div className="flex items-start justify-between gap-4 border-b border-border p-4">
        <div className="space-y-1">
          <Label htmlFor="ai-scheduling-enabled" className="flex items-center gap-2 text-sm font-semibold">
            <CalendarClock className="h-4 w-4" />
            {t("settings.aiAssistant.scheduling.title")}
          </Label>
          <p className="max-w-2xl text-xs leading-5 text-muted-foreground">
            {t("settings.aiAssistant.scheduling.description")}
          </p>
        </div>
      </div>

      <div className="space-y-4 p-4">
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border bg-background p-3">
          <div className="space-y-0.5">
            <Label htmlFor="ai-scheduling-enabled">{t("settings.aiAssistant.scheduling.enabled")}</Label>
            <p className="text-xs text-muted-foreground">{t("settings.aiAssistant.scheduling.enabledHint")}</p>
          </div>
          <Switch
            id="ai-scheduling-enabled"
            checked={form.enabled}
            onCheckedChange={(enabled) => update({ enabled })}
          />
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <div className="space-y-2">
            <Label>{t("settings.aiAssistant.scheduling.mode")}</Label>
            <Select value={form.mode} onValueChange={(mode) => update({ mode: mode as AiSchedulingMode })}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {MODES.map((mode) => (
                  <SelectItem key={mode} value={mode}>
                    {t(MODE_LABEL_KEYS[mode])}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>{t("settings.aiAssistant.scheduling.defaultUser")}</Label>
            <Select
              value={form.default_user_id != null ? String(form.default_user_id) : NO_DEFAULT_USER}
              onValueChange={(value) =>
                update({ default_user_id: value === NO_DEFAULT_USER ? null : Number(value) })
              }
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NO_DEFAULT_USER}>{t("settings.aiAssistant.scheduling.none")}</SelectItem>
                {calendarUsers.map((user) => (
                  <SelectItem key={user.id} value={String(user.id)}>
                    {user.name} ({user.email})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {calendarUsers.length === 0 && (
              <div
                role="alert"
                className="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-600 dark:text-amber-400"
              >
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{t("settings.aiAssistant.scheduling.noCalendarUsers")}</span>
              </div>
            )}
          </div>
        </div>

        {form.mode === "criteria" && (
          <div className="space-y-2">
            <Label htmlFor="ai-scheduling-criteria">{t("settings.aiAssistant.scheduling.criteria")}</Label>
            <Textarea
              id="ai-scheduling-criteria"
              value={form.criteria ?? ""}
              onChange={(e) => update({ criteria: e.target.value })}
              placeholder={t("settings.aiAssistant.scheduling.criteriaPlaceholder")}
              maxLength={CRITERIA_MAX_LENGTH}
              rows={4}
              className="resize-y bg-background text-sm"
            />
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {NUMERIC_FIELDS.map(({ field, labelKey, min, max }) => (
            <div key={field} className="space-y-2">
              <Label htmlFor={`ai-scheduling-${field}`}>{t(labelKey)}</Label>
              <Input
                id={`ai-scheduling-${field}`}
                type="number"
                min={min}
                max={max}
                value={form[field]}
                onChange={(e) => {
                  const next = { ...form }
                  next[field] = Number.isNaN(e.target.valueAsNumber) ? 0 : e.target.valueAsNumber
                  setForm(next)
                }}
                className="bg-background"
              />
            </div>
          ))}
        </div>

        <div className="space-y-2">
          <Label htmlFor="ai-scheduling-timezone">{t("settings.aiAssistant.scheduling.timezone")}</Label>
          <Input
            id="ai-scheduling-timezone"
            list="ai-scheduling-timezones"
            value={form.timezone}
            onChange={(e) => update({ timezone: e.target.value })}
            className="bg-background md:max-w-sm"
          />
          <datalist id="ai-scheduling-timezones">
            {timezones.map((tz) => (
              <option key={tz} value={tz} />
            ))}
          </datalist>
        </div>

        <div className="space-y-2">
          <Label>{t("settings.aiAssistant.scheduling.workingHours")}</Label>
          <div className="divide-y divide-border rounded-lg border border-border bg-background">
            {WEEKDAYS.map((day) => {
              const ranges = form.working_hours[day] ?? []
              return (
                <div key={day} className="flex flex-col gap-3 p-3 sm:flex-row sm:items-start">
                  <div className="flex w-40 shrink-0 items-center gap-3">
                    <Switch
                      id={`ai-scheduling-day-${day}`}
                      checked={ranges.length > 0}
                      onCheckedChange={(active) => setDayRanges(day, active ? [{ ...DEFAULT_RANGE }] : [])}
                    />
                    <Label htmlFor={`ai-scheduling-day-${day}`}>
                      {t(`settings.aiAssistant.scheduling.days.${day}`)}
                    </Label>
                  </div>

                  {ranges.length === 0 ? (
                    <p className="pt-1.5 text-xs text-muted-foreground">
                      {t("settings.aiAssistant.scheduling.closed")}
                    </p>
                  ) : (
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                      {ranges.map((range, index) => (
                        <div key={index} className="flex items-center gap-1">
                          <Input
                            type="time"
                            value={range.start}
                            onChange={(e) =>
                              setDayRanges(
                                day,
                                ranges.map((r, i) => (i === index ? { ...r, start: e.target.value } : r)),
                              )
                            }
                            className="h-8 w-28"
                          />
                          <span className="text-muted-foreground">–</span>
                          <Input
                            type="time"
                            value={range.end}
                            onChange={(e) =>
                              setDayRanges(
                                day,
                                ranges.map((r, i) => (i === index ? { ...r, end: e.target.value } : r)),
                              )
                            }
                            className="h-8 w-28"
                          />
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8"
                            aria-label={t("settings.aiAssistant.scheduling.removeRange")}
                            onClick={() => setDayRanges(day, ranges.filter((_, i) => i !== index))}
                          >
                            <X className="h-4 w-4" />
                          </Button>
                        </div>
                      ))}
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8"
                        disabled={ranges.length >= MAX_RANGES_PER_DAY}
                        onClick={() => setDayRanges(day, [...ranges, { ...DEFAULT_RANGE }])}
                      >
                        <Plus className="h-4 w-4" />
                        {t("settings.aiAssistant.scheduling.addRange")}
                      </Button>
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        </div>

        <div className="flex justify-end">
          <Button onClick={handleSave} disabled={saving}>
            {saving && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            {t("common.save")}
          </Button>
        </div>
      </div>
    </div>
  )
}
