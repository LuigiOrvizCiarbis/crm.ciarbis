"use client"

import { ExternalLink, RefreshCw } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { FOOTER_LABEL_MAX } from "@/lib/flow-builder/limits"
import {
  REPEATER_QUESTION_MAX,
  groupScreenCount,
  repeaterFields,
  repeaterLabelMax,
  snapshotFields,
  snapshotMatches,
} from "@/lib/flow-builder/repeater"
import { keyFromLabel } from "@/lib/flow-builder/slug"
import type { FlowScreen, RepeaterScreen, Target } from "@/lib/flow-builder/types"

import { BuilderField, FieldErrorText } from "./BuilderField"
import { DestinationSelect } from "./DestinationSelect"

const FIELDS_SETTINGS_PATH = "/configuracion/campos"
/** Filas que se ofrecen por defecto al elegir el campo. */
const DEFAULT_ROWS = 4

type RepeaterPatch = Partial<Omit<RepeaterScreen, "type" | "id" | "uid" | "next" | "position">>

interface RepeaterEditorProps {
  group: RepeaterScreen
  index: number
  screens: FlowScreen[]
  hasAppointment: boolean
  errors: Record<string, string[]>
  contactFields: ContactField[]
  /** Claves de las demás preguntas y grupos del formulario. */
  otherKeys: string[]
  /** Pantallas que puede ocupar este grupo sin pasar las 10 de Meta. */
  screenBudget: number
  onChange: (patch: RepeaterPatch) => void
  onChangeNext: (next: Target) => void
}

/** Panel de un grupo repetible: el campo repeater, sus etiquetas cortas, las filas y las preguntas que genera. */
export function RepeaterEditor({
  group,
  index,
  screens,
  hasAppointment,
  errors,
  contactFields,
  otherKeys,
  screenBudget,
  onChange,
  onChangeNext,
}: RepeaterEditorProps) {
  const { t } = useTranslation()
  const uid = `repeater-${group.uid}`
  const candidates = repeaterFields(contactFields)
  const field = contactFields.find((item) => item.key === group.field_key)
  const stale = field !== undefined && !snapshotMatches(group, field)
  const extraScreens = groupScreenCount(group) - Math.max(1, group.max_rows)
  const minRows = Math.max(group.min_rows, 1)
  const maxRows = Math.max(minRows, Math.min(field?.options?.max_items ?? 10, screenBudget - extraScreens))

  const chooseField = (key: string) => {
    const chosen = contactFields.find((item) => item.key === key)
    if (!chosen) return
    const min = chosen.options?.min_items ?? 0
    const rows = Math.max(Math.max(min, 1), Math.min(DEFAULT_ROWS, chosen.options?.max_items ?? 10, screenBudget))
    const label = chosen.label
    onChange({
      field_key: chosen.key,
      key: keyFromLabel(label, otherKeys),
      fields: snapshotFields(chosen),
      min_rows: min,
      max_rows: rows,
      ask_first: min === 0,
      title: label,
      row_title: `${label} {n}`,
      ask_first_label: Array.from(t("flows.builder.repeater.askFirstDefault", { label: label.toLowerCase() })).slice(0, REPEATER_QUESTION_MAX).join(""),
    })
  }

  const refresh = () => {
    if (!field) return
    const min = field.options?.min_items ?? 0
    onChange({
      fields: snapshotFields(field, group.fields),
      min_rows: min,
      max_rows: Math.min(Math.max(group.max_rows, Math.max(min, 1)), field.options?.max_items ?? 10),
      ask_first: group.ask_first && min === 0,
    })
  }

  return (
    <div className="space-y-6">
      <BuilderField id={`${uid}-field`} label={t("flows.builder.repeater.field")} errors={errors.field_key} hint={t("flows.builder.repeater.fieldHint")}>
        {candidates.length > 0 ? (
          <Select value={group.field_key || undefined} onValueChange={chooseField}>
            <SelectTrigger id={`${uid}-field`} className="w-full" aria-invalid={!!errors.field_key}>
              <SelectValue placeholder={t("flows.builder.repeater.fieldPlaceholder")} />
            </SelectTrigger>
            <SelectContent>
              {candidates.map((item) => (
                <SelectItem key={item.key} value={item.key}>
                  {item.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <div className="space-y-2 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground">
            <p>{t("flows.builder.repeater.noFields")}</p>
            <Button asChild variant="outline" size="sm">
              <a href={FIELDS_SETTINGS_PATH} target="_blank" rel="noreferrer">
                <ExternalLink className="size-4" />
                {t("flows.builder.repeater.createField")}
              </a>
            </Button>
          </div>
        )}
      </BuilderField>

      {stale ? (
        <div className="flex items-start gap-3 rounded-md border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm text-amber-800 dark:text-amber-300" role="status">
          <p className="min-w-0 flex-1">{t("flows.builder.repeater.stale", { field: field.label })}</p>
          <Button size="sm" variant="outline" className="h-7 shrink-0" onClick={refresh}>
            <RefreshCw className="size-3.5" />
            {t("flows.builder.repeater.refresh")}
          </Button>
        </div>
      ) : (
        <FieldErrorText errors={errors.fields} />
      )}

      {group.field_key ? (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <BuilderField id={`${uid}-title`} label={t("flows.builder.repeater.title")} errors={errors.title}>
              <Input id={`${uid}-title`} value={group.title} aria-invalid={!!errors.title} onChange={(event) => onChange({ title: event.target.value })} />
            </BuilderField>
            <BuilderField id={`${uid}-row-title`} label={t("flows.builder.repeater.rowTitle")} errors={errors.row_title} hint={t("flows.builder.repeater.rowTitleHint")}>
              <Input id={`${uid}-row-title`} value={group.row_title} aria-invalid={!!errors.row_title} onChange={(event) => onChange({ row_title: event.target.value })} />
            </BuilderField>
          </div>

          <section className="space-y-3" aria-labelledby={`${uid}-fields`}>
            <div>
              <h3 id={`${uid}-fields`} className="text-xs font-medium text-muted-foreground">
                {t("flows.builder.repeater.fields")}
              </h3>
              <p className="mt-0.5 text-xs text-muted-foreground">{t("flows.builder.repeater.fieldsHint")}</p>
            </div>
            <ul className="divide-y divide-border rounded-md border border-border">
              {group.fields.map((item, i) => (
                <li key={item.key} className="space-y-1.5 px-3 py-2.5">
                  <BuilderField
                    id={`${uid}-field-${i}`}
                    label={`${t(`fields.typeLabels.${item.type}`)}${item.required ? ` · ${t("flows.builder.repeater.requiredInCrm")}` : ""}`}
                    value={item.label}
                    max={repeaterLabelMax(item)}
                    errors={[...(errors[`fields.${i}.label`] ?? []), ...(errors[`fields.${i}.choices`] ?? [])]}
                  >
                    <Input
                      id={`${uid}-field-${i}`}
                      value={item.label}
                      aria-invalid={!!errors[`fields.${i}.label`]}
                      onChange={(event) =>
                        onChange({ fields: group.fields.map((current, j) => (j === i ? { ...current, label: event.target.value } : current)) })
                      }
                    />
                  </BuilderField>
                  {item.type === "select" ? (
                    <p className="truncate text-xs text-muted-foreground" title={(item.choices ?? []).join(", ")}>
                      {(item.choices ?? []).join(" · ")}
                    </p>
                  ) : null}
                </li>
              ))}
            </ul>
          </section>

          <div className="grid gap-4 sm:grid-cols-2">
            <BuilderField
              id={`${uid}-rows`}
              label={t("flows.builder.repeater.maxRows")}
              errors={errors.max_rows}
              hint={t("flows.builder.repeater.maxRowsHint", { max: maxRows })}
            >
              <Select value={String(group.max_rows)} onValueChange={(value) => onChange({ max_rows: Number(value) })}>
                <SelectTrigger id={`${uid}-rows`} className="w-full" aria-invalid={!!errors.max_rows}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Array.from({ length: Math.max(maxRows, group.max_rows) - minRows + 1 }, (_, i) => minRows + i).map((rows) => (
                    <SelectItem key={rows} value={String(rows)} disabled={rows > maxRows}>
                      {rows}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </BuilderField>
            <BuilderField
              id={`${uid}-footer`}
              label={t("flows.builder.screen.footerLabel")}
              value={group.footer_label}
              max={FOOTER_LABEL_MAX}
              errors={errors.footer_label}
            >
              <Input id={`${uid}-footer`} value={group.footer_label} aria-invalid={!!errors.footer_label} onChange={(event) => onChange({ footer_label: event.target.value })} />
            </BuilderField>
          </div>

          <BuilderField
            id={`${uid}-more`}
            label={t("flows.builder.repeater.moreLabel")}
            value={group.more_label}
            max={REPEATER_QUESTION_MAX}
            errors={errors.more_label}
            hint={t("flows.builder.repeater.moreHint")}
          >
            <Input id={`${uid}-more`} value={group.more_label} aria-invalid={!!errors.more_label} onChange={(event) => onChange({ more_label: event.target.value })} />
          </BuilderField>

          {group.min_rows === 0 ? (
            <div className="space-y-3">
              <div className="flex items-center justify-between gap-3">
                <Label htmlFor={`${uid}-ask`} className="text-sm">
                  {t("flows.builder.repeater.askFirst")}
                </Label>
                <Switch id={`${uid}-ask`} checked={group.ask_first} onCheckedChange={(ask_first) => onChange({ ask_first })} />
              </div>
              {group.ask_first ? (
                <BuilderField
                  id={`${uid}-ask-label`}
                  label={t("flows.builder.repeater.askFirstLabel")}
                  value={group.ask_first_label}
                  max={REPEATER_QUESTION_MAX}
                  errors={errors.ask_first_label}
                  hint={t("flows.builder.repeater.askFirstHint")}
                >
                  <Input
                    id={`${uid}-ask-label`}
                    value={group.ask_first_label}
                    aria-invalid={!!errors.ask_first_label}
                    onChange={(event) => onChange({ ask_first_label: event.target.value })}
                  />
                </BuilderField>
              ) : null}
            </div>
          ) : null}

          <div className="grid gap-4 sm:grid-cols-2">
            <BuilderField id={`${uid}-key`} label={t("flows.builder.block.key")} errors={errors.key} hint={t("flows.builder.repeater.keyHint")}>
              <Input
                id={`${uid}-key`}
                value={group.key}
                className="font-mono text-sm"
                aria-invalid={!!errors.key}
                onChange={(event) => onChange({ key: event.target.value })}
              />
            </BuilderField>
            <BuilderField id={`${uid}-next`} label={t("flows.builder.repeater.next")} errors={errors.next}>
              <DestinationSelect
                id={`${uid}-next`}
                value={group.next}
                index={index}
                screens={screens}
                hasAppointment={hasAppointment}
                invalid={!!errors.next}
                onChange={onChangeNext}
              />
            </BuilderField>
          </div>
        </>
      ) : null}
    </div>
  )
}
