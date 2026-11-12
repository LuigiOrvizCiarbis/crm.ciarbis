"use client"

import { useState, type ReactNode } from "react"
import { ChevronDown, Lock, Plus, Trash2, TriangleAlert, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import {
  compatibleTargets,
  customFieldKey,
  findField,
  lockedOptions,
  prefillSources,
  CUSTOM_PREFILL_PREFIX,
} from "@/lib/flow-builder/compat"
import { HELPER_TEXT_MAX, LABEL_MAX, OPTION_COUNT_MAX, OPTION_TITLE_MAX, TEXT_MAX, isChoiceBlock, isTextBlock } from "@/lib/flow-builder/limits"
import { keyFromLabel } from "@/lib/flow-builder/slug"
import { charCount } from "@/lib/flow-builder/validate"
import type {
  ChoiceBlock,
  FlowBlock,
  MapsTo,
  PrefillSource,
  QuestionBlock,
  ShortTextBlock,
  TextBlock,
} from "@/lib/flow-builder/types"
import { cn } from "@/lib/utils"

import { BlockTypeIcon } from "./BlockTypeIcon"
import { BuilderField, FieldErrorText } from "./BuilderField"

type Translate = (key: string, params?: Record<string, string | number>) => string

const NONE = "__none__"
const INPUT_TYPES: ShortTextBlock["input_type"][] = ["text", "email", "number", "phone"]

interface BlockEditorProps {
  block: FlowBlock
  /** Errores del bloque por campo (`label`, `options.0.title`, o "" para el bloque entero). */
  errors: Record<string, string[]>
  contactFields: ContactField[]
  /** Claves de las demás preguntas del formulario. */
  otherKeys: string[]
  defaultOpen?: boolean
  dragHandle: ReactNode
  onChange: (block: FlowBlock) => void
  onRemove: () => void
}

/** Nombre visible de un destino de "Guardar en". */
export function targetLabel(target: string, fields: ContactField[], t: Translate): string {
  if (target === "contact.name") return t("flows.builder.targets.contactName")
  if (target === "contact.email") return t("flows.builder.targets.contactEmail")
  const field = findField(fields, customFieldKey(target))
  return field ? field.label : t("flows.builder.targets.missing", { key: customFieldKey(target) ?? target })
}

/** Nombre visible de una fuente de "Precargar con". */
export function prefillLabel(source: string, fields: ContactField[], t: Translate): string {
  if (source === "contact_name") return t("flows.builder.prefill.contactName")
  if (source === "contact_email") return t("flows.builder.prefill.contactEmail")
  if (source === "contact_phone") return t("flows.builder.prefill.contactPhone")
  const key = source.slice(CUSTOM_PREFILL_PREFIX.length)
  return findField(fields, key)?.label ?? t("flows.builder.targets.missing", { key })
}

export function BlockEditor({
  block,
  errors,
  contactFields,
  otherKeys,
  defaultOpen = false,
  dragHandle,
  onChange,
  onRemove,
}: BlockEditorProps) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(defaultOpen)
  const errorCount = Object.values(errors).reduce((total, messages) => total + messages.length, 0)
  // Un bloque con errores queda abierto: hay que verlos para corregirlos.
  const expanded = open || errorCount > 0
  const uid = block.uid ?? "block"
  const summary = isTextBlock(block) ? block.text : block.label

  return (
    <div
      className={cn(
        "rounded-lg border bg-card",
        errorCount > 0 ? "border-destructive/60" : "border-border",
      )}
    >
      <div className="flex items-center gap-2 px-2 py-2">
        {dragHandle}
        <button
          type="button"
          className="flex min-w-0 flex-1 items-center gap-2 text-left"
          onClick={() => setOpen((value) => !value)}
          aria-expanded={expanded}
          aria-controls={`${uid}-body`}
        >
          <BlockTypeIcon type={block.type} className="size-4 shrink-0 text-muted-foreground" />
          <span className="shrink-0 text-xs font-medium text-muted-foreground">
            {t(`flows.builder.blockTypes.${block.type}`)}
          </span>
          <span className="min-w-0 truncate text-sm text-foreground">
            {summary || <span className="text-muted-foreground">{t("flows.builder.block.untitled")}</span>}
            {!isTextBlock(block) && block.required ? <span className="text-destructive"> *</span> : null}
          </span>
          {errorCount > 0 ? (
            <TriangleAlert className="size-4 shrink-0 text-destructive" aria-label={t("flows.builder.block.hasErrors")} />
          ) : null}
          <ChevronDown
            className={cn("ml-auto size-4 shrink-0 text-muted-foreground transition-transform", expanded && "rotate-180")}
            aria-hidden
          />
        </button>
        <Button
          variant="ghost"
          size="icon"
          className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
          onClick={onRemove}
          aria-label={t("flows.builder.block.remove")}
        >
          <Trash2 className="size-4" />
        </Button>
      </div>

      {expanded ? (
        <div id={`${uid}-body`} className="space-y-4 border-t border-border px-4 py-4">
          <FieldErrorText errors={errors[""]} />
          {isTextBlock(block) ? (
            <TextBlockFields block={block} uid={uid} errors={errors} onChange={onChange} t={t} />
          ) : (
            <QuestionFields
              block={block}
              uid={uid}
              errors={errors}
              contactFields={contactFields}
              otherKeys={otherKeys}
              onChange={onChange}
              t={t}
            />
          )}
        </div>
      ) : null}
    </div>
  )
}

function TextBlockFields({
  block,
  uid,
  errors,
  onChange,
  t,
}: {
  block: TextBlock
  uid: string
  errors: Record<string, string[]>
  onChange: (block: FlowBlock) => void
  t: Translate
}) {
  const max = TEXT_MAX[block.type]
  const multiline = block.type === "body" || block.type === "caption"
  const id = `${uid}-text`

  return (
    <BuilderField id={id} label={t("flows.builder.block.text")} value={block.text} max={max} errors={errors.text}>
      {multiline ? (
        <Textarea
          id={id}
          value={block.text}
          rows={block.type === "body" ? 4 : 2}
          aria-invalid={!!errors.text}
          onChange={(event) => onChange({ ...block, text: event.target.value })}
        />
      ) : (
        <Input
          id={id}
          value={block.text}
          aria-invalid={!!errors.text}
          onChange={(event) => onChange({ ...block, text: event.target.value })}
        />
      )}
    </BuilderField>
  )
}

interface QuestionFieldsProps {
  block: QuestionBlock
  uid: string
  errors: Record<string, string[]>
  contactFields: ContactField[]
  otherKeys: string[]
  onChange: (block: FlowBlock) => void
  t: Translate
}

function QuestionFields({ block, uid, errors, contactFields, otherKeys, onChange, t }: QuestionFieldsProps) {
  // La clave sigue a la etiqueta mientras el usuario no la escriba a mano.
  const [keyTouched, setKeyTouched] = useState(() => block.key !== keyFromLabel(block.label, otherKeys))

  const setLabel = (label: string) => {
    onChange({ ...block, label, key: keyTouched ? block.key : keyFromLabel(label, otherKeys) })
  }

  const setTarget = (value: string) => {
    const maps_to = value === NONE ? null : (value as MapsTo)
    const next = { ...block, maps_to } as QuestionBlock
    const locked = isChoiceBlock(next) ? lockedOptions(next, contactFields) : null
    onChange(locked && isChoiceBlock(next) ? { ...next, options: locked } : next)
  }

  const targets: string[] = compatibleTargets(block, contactFields)
  if (block.maps_to && !targets.includes(block.maps_to)) targets.push(block.maps_to)

  const hasHelper = block.type === "short_text" || block.type === "long_text" || block.type === "date"
  const helperText = "helper_text" in block ? (block.helper_text ?? "") : ""

  return (
    <>
      <BuilderField
        id={`${uid}-label`}
        label={t("flows.builder.block.label")}
        value={block.label}
        max={LABEL_MAX[block.type]}
        errors={errors.label}
      >
        <Input
          id={`${uid}-label`}
          value={block.label}
          aria-invalid={!!errors.label}
          onChange={(event) => setLabel(event.target.value)}
        />
      </BuilderField>

      <div className="grid gap-4 sm:grid-cols-2">
        <BuilderField
          id={`${uid}-key`}
          label={t("flows.builder.block.key")}
          errors={errors.key}
          hint={t("flows.builder.block.keyHint")}
        >
          <Input
            id={`${uid}-key`}
            value={block.key}
            className="font-mono text-sm"
            aria-invalid={!!errors.key}
            onChange={(event) => {
              setKeyTouched(true)
              onChange({ ...block, key: event.target.value })
            }}
          />
        </BuilderField>

        {block.type === "short_text" ? (
          <BuilderField id={`${uid}-input-type`} label={t("flows.builder.block.inputType")} errors={errors.input_type}>
            <Select
              value={block.input_type}
              onValueChange={(value) => onChange({ ...block, input_type: value as ShortTextBlock["input_type"] })}
            >
              <SelectTrigger id={`${uid}-input-type`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {INPUT_TYPES.map((type) => (
                  <SelectItem key={type} value={type}>
                    {t(`flows.builder.inputTypes.${type}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </BuilderField>
        ) : null}
      </div>

      {hasHelper ? (
        <BuilderField
          id={`${uid}-helper`}
          label={t("flows.builder.block.helperText")}
          value={helperText}
          max={HELPER_TEXT_MAX}
          errors={errors.helper_text}
        >
          <Input
            id={`${uid}-helper`}
            value={helperText}
            aria-invalid={!!errors.helper_text}
            onChange={(event) => onChange({ ...block, helper_text: event.target.value || null } as QuestionBlock)}
          />
        </BuilderField>
      ) : null}

      {isChoiceBlock(block) ? (
        <OptionsEditor block={block} uid={uid} errors={errors} contactFields={contactFields} onChange={onChange} t={t} />
      ) : null}

      <div className="flex items-center justify-between gap-3">
        <Label htmlFor={`${uid}-required`} className="text-sm">
          {t("flows.builder.block.required")}
        </Label>
        <Switch
          id={`${uid}-required`}
          checked={block.required}
          onCheckedChange={(required) => onChange({ ...block, required })}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <BuilderField
          id={`${uid}-maps-to`}
          label={t("flows.builder.block.mapsTo")}
          errors={errors.maps_to}
        >
          <Select value={block.maps_to ?? NONE} onValueChange={setTarget}>
            <SelectTrigger id={`${uid}-maps-to`} className="w-full" aria-invalid={!!errors.maps_to}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={NONE}>{t("flows.builder.block.mapsToNone")}</SelectItem>
              {targets.map((target) => (
                <SelectItem key={target} value={target}>
                  {targetLabel(target, contactFields, t)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </BuilderField>

        {block.type === "short_text" || block.type === "long_text" ? (
          <PrefillSelect block={block} uid={uid} errors={errors} contactFields={contactFields} onChange={onChange} t={t} />
        ) : null}
      </div>
    </>
  )
}

function PrefillSelect({
  block,
  uid,
  errors,
  contactFields,
  onChange,
  t,
}: {
  block: Extract<QuestionBlock, { type: "short_text" | "long_text" }>
  uid: string
  errors: Record<string, string[]>
  contactFields: ContactField[]
  onChange: (block: FlowBlock) => void
  t: Translate
}) {
  const sources: string[] = prefillSources(contactFields)
  if (block.prefill && !sources.includes(block.prefill)) sources.push(block.prefill)

  return (
    <BuilderField id={`${uid}-prefill`} label={t("flows.builder.block.prefill")} errors={errors.prefill}>
      <Select
        value={block.prefill ?? NONE}
        onValueChange={(value) => onChange({ ...block, prefill: value === NONE ? null : (value as PrefillSource) })}
      >
        <SelectTrigger id={`${uid}-prefill`} className="w-full" aria-invalid={!!errors.prefill}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NONE}>{t("flows.builder.prefill.none")}</SelectItem>
          {sources.map((source) => (
            <SelectItem key={source} value={source}>
              {prefillLabel(source, contactFields, t)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </BuilderField>
  )
}

function OptionsEditor({
  block,
  uid,
  errors,
  contactFields,
  onChange,
  t,
}: {
  block: ChoiceBlock
  uid: string
  errors: Record<string, string[]>
  contactFields: ContactField[]
  onChange: (block: FlowBlock) => void
  t: Translate
}) {
  const locked = lockedOptions(block, contactFields) !== null
  const lockedField = locked ? findField(contactFields, customFieldKey(block.maps_to)) : undefined
  const max = OPTION_COUNT_MAX[block.type]

  // El id de la opción es lo que devuelve WhatsApp: sigue al título para que la respuesta sea legible.
  const setTitle = (index: number, title: string) =>
    onChange({
      ...block,
      options: block.options.map((option, i) => (i === index ? { id: title, title } : option)),
    })

  const addOption = () => {
    const taken = new Set(block.options.map((option) => option.id))
    let n = block.options.length + 1
    let title = t("flows.builder.defaults.optionN", { n })
    while (taken.has(title)) {
      n += 1
      title = t("flows.builder.defaults.optionN", { n })
    }
    onChange({ ...block, options: [...block.options, { id: title, title }] })
  }

  return (
    <div className="space-y-2">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-xs font-medium text-muted-foreground">{t("flows.builder.block.options")}</span>
        <span className="font-mono text-[11px] tabular-nums text-muted-foreground/70">
          {block.options.length}/{max}
        </span>
      </div>
      {locked ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Lock className="size-3.5" aria-hidden />
          {t("flows.builder.block.optionsLocked", { field: lockedField?.label ?? "" })}
        </p>
      ) : null}
      <ul className="space-y-2">
        {block.options.map((option, index) => {
          const optionErrors = [...(errors[`options.${index}.title`] ?? []), ...(errors[`options.${index}.id`] ?? [])]
          const id = `${uid}-option-${index}`
          return (
            <li key={index} className="space-y-1">
              <div className="flex items-center gap-2">
                <Input
                  id={id}
                  value={option.title}
                  disabled={locked}
                  aria-label={t("flows.builder.block.optionLabel", { n: index + 1 })}
                  aria-invalid={optionErrors.length > 0}
                  onChange={(event) => setTitle(index, event.target.value)}
                />
                <span className="w-12 shrink-0 text-right font-mono text-[11px] tabular-nums text-muted-foreground/70">
                  {charCount(option.title)}/{OPTION_TITLE_MAX}
                </span>
                {!locked ? (
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0 text-muted-foreground"
                    onClick={() => onChange({ ...block, options: block.options.filter((_, i) => i !== index) })}
                    aria-label={t("flows.builder.block.removeOption")}
                  >
                    <X className="size-4" />
                  </Button>
                ) : null}
              </div>
              <FieldErrorText errors={optionErrors} />
            </li>
          )
        })}
      </ul>
      <FieldErrorText errors={errors.options} />
      {!locked ? (
        <Button variant="ghost" size="sm" onClick={addOption} disabled={block.options.length >= max}>
          <Plus className="size-4" />
          {t("flows.builder.block.addOption")}
        </Button>
      ) : null}
    </div>
  )
}
