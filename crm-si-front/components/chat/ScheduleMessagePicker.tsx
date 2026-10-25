"use client"

import { useState } from "react"
import { addDays, addHours, format, isAfter, nextMonday, setHours, startOfDay, startOfHour } from "date-fns"
import { enUS, es } from "date-fns/locale"
import { AlertTriangle, Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { Input } from "@/components/ui/input"
import { useTranslation } from "@/hooks/useTranslation"

const MAX_DAYS_AHEAD = 60
const MORNING_HOUR = 9

interface ScheduleMessagePickerProps {
  /** Fecha precargada (al reprogramar). Sin ella, la próxima hora en punto. */
  initialDate?: Date
  /** Último entrante + 24h: pasada esa hora Meta rechaza mensajes libres. */
  windowExpiresAt?: string | null
  /** "save" al editar un programado existente; cambia el texto del botón. */
  mode?: "create" | "save"
  onConfirm: (scheduledFor: Date) => Promise<unknown> | void
}

function defaultDate(): Date {
  return startOfHour(addHours(new Date(), 2))
}

function combine(day: Date, time: string): Date {
  const [hours, minutes] = time.split(":").map(Number)
  const result = new Date(day)
  result.setHours(hours || 0, minutes || 0, 0, 0)
  return result
}

function roundUpToFiveMinutes(date: Date): Date {
  const step = 5 * 60 * 1000
  return new Date(Math.ceil(date.getTime() / step) * step)
}

function presets(now: Date) {
  const morning = (day: Date) => setHours(startOfDay(day), MORNING_HOUR)
  return [
    { key: "schedulePresetInOneHour", date: roundUpToFiveMinutes(addHours(now, 1)) },
    { key: "schedulePresetTomorrowMorning", date: morning(addDays(now, 1)) },
    { key: "schedulePresetNextMonday", date: morning(nextMonday(now)) },
  ]
}

/**
 * Selector de fecha/hora para programar un mensaje: atajos, calendario y
 * hora. Valida que sea futuro y avisa si cae fuera de la ventana de 24h,
 * pero no bloquea (el contacto puede escribir antes).
 */
export function ScheduleMessagePicker({ initialDate, windowExpiresAt, mode = "create", onConfirm }: ScheduleMessagePickerProps) {
  const { t, language } = useTranslation()
  const locale = language === "en" ? enUS : es
  const [initial] = useState(() => initialDate ?? defaultDate())
  const [day, setDay] = useState<Date>(initial)
  const [time, setTime] = useState(() => format(initial, "HH:mm"))
  const [isSubmitting, setIsSubmitting] = useState(false)

  const now = new Date()
  const scheduledFor = combine(day, time)
  const formatWhen = (date: Date) => format(date, "EEE d MMM, HH:mm", { locale })

  const error = !isAfter(scheduledFor, now)
    ? t("chats.scheduleInPast")
    : isAfter(scheduledFor, addDays(now, MAX_DAYS_AHEAD))
      ? t("chats.scheduleTooFar")
      : null
  const windowEnd = windowExpiresAt ? new Date(windowExpiresAt) : null
  const windowWarning = windowEnd && isAfter(scheduledFor, windowEnd)
    ? t("chats.scheduleWindowWarning", { when: formatWhen(windowEnd) })
    : null

  const applyPreset = (date: Date) => {
    setDay(date)
    setTime(format(date, "HH:mm"))
  }

  const handleConfirm = async () => {
    if (error || isSubmitting) return
    setIsSubmitting(true)
    try {
      await onConfirm(scheduledFor)
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-1.5">
        {presets(now).map((preset) => (
          <Button key={preset.key} type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => applyPreset(preset.date)}>
            {t(`chats.${preset.key}`)}
          </Button>
        ))}
      </div>

      <Calendar
        mode="single"
        selected={day}
        onSelect={(selected) => selected && setDay(selected)}
        disabled={{ before: startOfDay(now), after: addDays(now, MAX_DAYS_AHEAD) }}
        locale={locale}
        className="mx-auto rounded-md border p-2"
      />

      <label className="flex items-center justify-between gap-3 text-sm">
        <span className="text-muted-foreground">{t("chats.scheduleTime")}</span>
        <Input type="time" value={time} onChange={(e) => setTime(e.target.value)} className="h-8 w-28" />
      </label>

      {error ? (
        <p className="text-xs text-destructive" role="alert">{error}</p>
      ) : windowWarning ? (
        <p className="flex gap-1.5 text-xs text-amber-600 dark:text-amber-400">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          {windowWarning}
        </p>
      ) : null}

      <Button type="button" onClick={handleConfirm} disabled={!!error || isSubmitting}>
        {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : null}
        {t(mode === "save" ? "chats.scheduleSave" : "chats.scheduleConfirm", { when: formatWhen(scheduledFor) })}
      </Button>
    </div>
  )
}
