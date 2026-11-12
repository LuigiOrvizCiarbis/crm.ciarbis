"use client"

import { CalendarClock } from "lucide-react"

import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { useTranslation } from "@/hooks/useTranslation"
import { APPOINTMENT_LABEL_MAX } from "@/lib/flow-builder/limits"
import { DEFAULT_APPOINTMENT } from "@/lib/flow-builder/presets"
import type { AppointmentConfig, LongTextBlock } from "@/lib/flow-builder/types"

import { BuilderField, FieldErrorText } from "./BuilderField"

const NONE = "__none__"

interface AppointmentSettingsProps {
  appointment: AppointmentConfig | null
  /** Preguntas de texto largo que pueden usarse como motivo del turno. */
  reasonCandidates: LongTextBlock[]
  errors: Record<string, string[]>
  onChange: (appointment: AppointmentConfig | null) => void
}

type TextField = Exclude<keyof AppointmentConfig, "reason_key">

const TEXT_FIELDS: Array<{ field: TextField; max?: number }> = [
  { field: "date_screen_title" },
  { field: "date_label", max: APPOINTMENT_LABEL_MAX.date_label },
  { field: "slot_screen_title" },
  { field: "slot_label", max: APPOINTMENT_LABEL_MAX.slot_label },
  { field: "confirm_label", max: APPOINTMENT_LABEL_MAX.confirm_label },
]

export function AppointmentSettings({
  appointment,
  reasonCandidates,
  errors,
  onChange,
}: AppointmentSettingsProps) {
  const { t } = useTranslation()

  return (
    <section className="space-y-4 rounded-lg border border-border bg-card p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 gap-2">
          <CalendarClock className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
          <div className="min-w-0">
            <Label htmlFor="appointment-enabled" className="text-sm font-medium">
              {t("flows.builder.appointment.title")}
            </Label>
            <p className="mt-0.5 text-xs text-muted-foreground">{t("flows.builder.appointment.description")}</p>
          </div>
        </div>
        <Switch
          id="appointment-enabled"
          checked={appointment !== null}
          onCheckedChange={(checked) =>
            onChange(checked ? { ...DEFAULT_APPOINTMENT, reason_key: reasonCandidates[0]?.key ?? null } : null)
          }
        />
      </div>

      {appointment ? (
        <div className="space-y-4">
          <p className="text-xs text-muted-foreground">{t("flows.builder.appointment.agendaHint")}</p>
          <FieldErrorText errors={errors[""]} />

          {TEXT_FIELDS.map(({ field, max }) => (
            <BuilderField
              key={field}
              id={`appointment-${field}`}
              label={t(`flows.builder.appointment.fields.${field}`)}
              value={appointment[field]}
              max={max}
              errors={errors[field]}
            >
              <Input
                id={`appointment-${field}`}
                value={appointment[field]}
                aria-invalid={!!errors[field]}
                onChange={(event) => onChange({ ...appointment, [field]: event.target.value })}
              />
            </BuilderField>
          ))}

          <BuilderField
            id="appointment-reason"
            label={t("flows.builder.appointment.fields.reason_key")}
            errors={errors.reason_key}
            hint={t("flows.builder.appointment.reasonHint")}
          >
            <Select
              value={appointment.reason_key ?? NONE}
              onValueChange={(value) => onChange({ ...appointment, reason_key: value === NONE ? null : value })}
            >
              <SelectTrigger id="appointment-reason" className="w-full" aria-invalid={!!errors.reason_key}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>{t("flows.builder.appointment.noReason")}</SelectItem>
                {reasonCandidates.map((block) => (
                  <SelectItem key={block.key} value={block.key}>
                    {block.label || block.key}
                  </SelectItem>
                ))}
                {appointment.reason_key && !reasonCandidates.some((block) => block.key === appointment.reason_key) ? (
                  <SelectItem value={appointment.reason_key}>{appointment.reason_key}</SelectItem>
                ) : null}
              </SelectContent>
            </Select>
          </BuilderField>
        </div>
      ) : null}
    </section>
  )
}
