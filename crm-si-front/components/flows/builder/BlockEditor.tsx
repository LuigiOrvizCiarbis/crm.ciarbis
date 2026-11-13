"use client"

import { useEffect, useState, type ReactNode } from "react"
import { ChevronDown, Lock, Plus, Trash2, TriangleAlert, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { CreateContactFieldDialog } from "@/components/flows/CreateContactFieldDialog"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import {
  adoptFieldChoices,
  compatibleTargets,
  creatableFieldTypes,
  customFieldKey,
  findField,
  lockedOptions,
  prefillSources,
  CUSTOM_DATA_PREFIX,
  CUSTOM_PREFILL_PREFIX,
} from "@/lib/flow-builder/compat"
import { OPTION_IMAGE_MAX_SIDE, imageDataUrl, isSupportedImage } from "@/lib/flow-builder/images"
import {
  HELPER_TEXT_MAX,
  LABEL_MAX,
  OPTION_IMAGE_MAX_BYTES,
  OPTION_TITLE_MAX,
  TEXT_MAX,
  isChoiceBlock,
  isQuestionBlock,
  isTextBlock,
  optionCountMax,
} from "@/lib/flow-builder/limits"
import { DEFAULT_TARGET, branchTarget, hasBranches, isBranchable } from "@/lib/flow-builder/routing"
import { keyFromLabel } from "@/lib/flow-builder/slug"
import { charCount } from "@/lib/flow-builder/validate"
import type {
  ChoiceBlock,
  ChoiceOption,
  FlowBlock,
  FlowScreen,
  MapsTo,
  PrefillSource,
  QuestionBlock,
  ShortTextBlock,
  SingleChoiceBlock,
  TextBlock,
} from "@/lib/flow-builder/types"
import { cn } from "@/lib/utils"

import { BlockTypeIcon } from "./BlockTypeIcon"
import { BuilderField, FieldErrorText } from "./BuilderField"
import { DestinationSelect } from "./DestinationSelect"
import { CarouselFields, ImageBlockFields, OptionImageButton } from "./ImageFields"

type Translate = (key: string, params?: Record<string, string | number>) => string

const NONE = "__none__"
const CREATE_FIELD = "__create__"
const INPUT_TYPES: ShortTextBlock["input_type"][] = ["text", "email", "number", "phone"]

/** Contexto para que una pregunta de opciones elija el camino del formulario. */
export interface BlockRouting {
  screens: FlowScreen[]
  /** Índice de la pantalla del bloque. */
  index: number
  hasAppointment: boolean
  /** Otra pregunta de la misma pantalla ya cambia el camino. */
  otherBlockBranches: boolean
}

interface BlockEditorProps {
  block: FlowBlock
  /** Errores del bloque por campo (`label`, `options.0.title`, o "" para el bloque entero). */
  errors: Record<string, string[]>
  contactFields: ContactField[]
  /** Claves de las demás preguntas del formulario. */
  otherKeys: string[]
  routing: BlockRouting
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

/** Texto con que se reconoce el bloque en su encabezado y al arrastrarlo. */
export function blockSummary(block: FlowBlock, t: Translate): string {
  if (isTextBlock(block)) return block.text
  if (block.type === "image") return block.alt_text ?? ""
  if (block.type === "image_carousel") return t("flows.builder.image.count", { count: block.images.length })
  return block.label
}

/** Primera imagen del bloque, para la miniatura del encabezado. */
function blockThumbnail(block: FlowBlock): string | null {
  const src = block.type === "image" ? block.src : block.type === "image_carousel" ? block.images[0]?.src : null
  return isSupportedImage(src) ? src : null
}

export function BlockEditor({
  block,
  errors,
  contactFields,
  otherKeys,
  routing,
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
  const summary = blockSummary(block, t)
  const thumbnail = blockThumbnail(block)

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
          {thumbnail ? (
            // eslint-disable-next-line @next/next/no-img-element -- base64 local, sin optimizar
            <img src={imageDataUrl(thumbnail)} alt="" className="size-6 shrink-0 rounded object-cover" />
          ) : null}
          <span className="min-w-0 truncate text-sm text-foreground">
            {summary || (thumbnail ? null : <span className="text-muted-foreground">{t("flows.builder.block.untitled")}</span>)}
            {isQuestionBlock(block) && block.required ? <span className="text-destructive"> *</span> : null}
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
          ) : block.type === "image" ? (
            <ImageBlockFields block={block} uid={uid} errors={errors} onChange={onChange} t={t} />
          ) : block.type === "image_carousel" ? (
            <CarouselFields block={block} uid={uid} errors={errors} onChange={onChange} t={t} />
          ) : (
            <QuestionFields
              block={block}
              uid={uid}
              errors={errors}
              contactFields={contactFields}
              otherKeys={otherKeys}
              routing={routing}
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
  routing: BlockRouting
  onChange: (block: FlowBlock) => void
  t: Translate
}

function QuestionFields({ block, uid, errors, contactFields, otherKeys, routing, onChange, t }: QuestionFieldsProps) {
  // La clave sigue a la etiqueta mientras el usuario no la escriba a mano.
  const [keyTouched, setKeyTouched] = useState(() => block.key !== keyFromLabel(block.label, otherKeys))

  const setLabel = (label: string) => {
    onChange({ ...block, label, key: keyTouched ? block.key : keyFromLabel(label, otherKeys) })
  }

  const requiredInCrm = (maps_to: string | null | undefined) => findField(contactFields, customFieldKey(maps_to))?.is_required === true
  const lockRequired = requiredInCrm(block.maps_to)

  // Una definición cargada inconsistente se corrige apenas se abre el bloque.
  useEffect(() => {
    if (lockRequired && !block.required) onChange({ ...block, required: true })
  }, [lockRequired, block, onChange])

  const canCreateField = usePermission("contact_fields.manage")
  const [creatingField, setCreatingField] = useState(false)

  /** Guarda la respuesta en el destino; un campo de selección impone sus opciones y uno obligatorio, la respuesta. */
  const mapTo = (maps_to: MapsTo | null, fields: ContactField[]) => {
    const field = findField(fields, customFieldKey(maps_to))
    const next = { ...block, maps_to, ...(field?.is_required ? { required: true } : {}) } as QuestionBlock
    const choices = field?.type === "select" || field?.type === "multi_select" ? (field.options?.choices ?? []) : null
    onChange(choices && isChoiceBlock(next) ? { ...next, options: adoptFieldChoices(next, choices) } : next)
  }

  const setTarget = (value: string) => {
    if (value === CREATE_FIELD) {
      setCreatingField(true)
      return
    }
    mapTo(value === NONE ? null : (value as MapsTo), contactFields)
  }

  const targets: string[] = compatibleTargets(block, contactFields)
  if (block.maps_to && !targets.includes(block.maps_to)) targets.push(block.maps_to)
  const hasCustomTarget = targets.some((target) => customFieldKey(target) !== null)

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

      {isBranchable(block) ? (
        <BranchEditor block={block} uid={uid} errors={errors.branches} routing={routing} onChange={onChange} t={t} />
      ) : null}

      <div className="space-y-1">
        <div className="flex items-center justify-between gap-3">
          <Label htmlFor={`${uid}-required`} className="text-sm">
            {t("flows.builder.block.required")}
          </Label>
          <Switch
            id={`${uid}-required`}
            checked={block.required}
            disabled={lockRequired}
            onCheckedChange={(required) => onChange({ ...block, required })}
          />
        </div>
        {lockRequired ? <p className="text-xs text-muted-foreground">{t("flows.builder.block.requiredInCrm")}</p> : null}
        <FieldErrorText errors={errors.required} />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <BuilderField
          id={`${uid}-maps-to`}
          label={t("flows.builder.block.mapsTo")}
          errors={errors.maps_to}
          hint={t(block.maps_to ? "flows.builder.block.mapsToHint" : "flows.builder.block.mapsToOptional")}
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
              {canCreateField ? (
                <>
                  <SelectSeparator />
                  <SelectItem value={CREATE_FIELD} className="text-primary focus:text-primary">
                    <span className="inline-flex items-center gap-2">
                      <Plus className="size-4" aria-hidden />
                      {t(hasCustomTarget ? "flows.builder.block.mapsToCreate" : "flows.builder.block.mapsToCreateFirst")}
                    </span>
                  </SelectItem>
                </>
              ) : null}
            </SelectContent>
          </Select>
        </BuilderField>

        {block.type === "short_text" || block.type === "long_text" ? (
          <PrefillSelect block={block} uid={uid} errors={errors} contactFields={contactFields} onChange={onChange} t={t} />
        ) : null}
      </div>

      {creatingField ? (
        <CreateContactFieldDialog
          onOpenChange={setCreatingField}
          types={creatableFieldTypes(block)}
          initialLabel={block.label}
          initialChoices={isChoiceBlock(block) ? block.options.map((option) => option.title.trim()).filter(Boolean) : []}
          choicesHint={t("flows.builder.block.newFieldChoicesHint")}
          onCreated={(field) => mapTo(`${CUSTOM_DATA_PREFIX}${field.key}`, [...contactFields, field])}
        />
      ) : null}
    </>
  )
}

/**
 * Reglas de camino de una pregunta de opciones: cada respuesta lleva a la pantalla siguiente,
 * a una posterior o al final. Las opciones arrancan con el destino de la pantalla.
 */
function BranchEditor({
  block,
  uid,
  errors,
  routing,
  onChange,
  t,
}: {
  block: SingleChoiceBlock
  uid: string
  errors: string[] | undefined
  routing: BlockRouting
  onChange: (block: FlowBlock) => void
  t: Translate
}) {
  const { screens, index, hasAppointment, otherBlockBranches } = routing
  const screenNext = screens[index]?.next ?? DEFAULT_TARGET
  const active = hasBranches(block)

  const toggle = (enabled: boolean) => {
    if (enabled) {
      onChange({ ...block, branches: Object.fromEntries(block.options.map((option) => [option.id, screenNext])) })
      return
    }
    const next = { ...block }
    delete next.branches
    onChange(next)
  }

  return (
    <div className="space-y-3 rounded-md border border-dashed border-border p-3">
      <div className="flex items-center justify-between gap-3">
        <Label htmlFor={`${uid}-branches`} className="text-sm">
          {t("flows.builder.routes.toggle")}
        </Label>
        <Switch
          id={`${uid}-branches`}
          checked={active}
          disabled={!active && otherBlockBranches}
          onCheckedChange={toggle}
        />
      </div>
      {!active && otherBlockBranches ? (
        <p className="text-xs text-muted-foreground">{t("flows.builder.routes.otherBlockBranches")}</p>
      ) : null}
      {active ? (
        <div className="space-y-2">
          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-2 text-xs font-medium text-muted-foreground">
            <span>{t("flows.builder.routes.ifAnswer")}</span>
            <span>{t("flows.builder.routes.goTo")}</span>
          </div>
          {block.options.map((option, optionIndex) => (
            <div key={`${option.id}-${optionIndex}`} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] items-center gap-2">
              <span className="truncate text-sm">{option.title || option.id}</span>
              <DestinationSelect
                id={`${uid}-branch-${optionIndex}`}
                value={branchTarget(block, option.id) ?? screenNext}
                index={index}
                screens={screens}
                hasAppointment={hasAppointment}
                invalid={!!errors}
                ariaLabel={t("flows.builder.routes.goToFor", { option: option.title || option.id })}
                onChange={(target) => onChange({ ...block, branches: { ...block.branches, [option.id]: target } })}
              />
            </div>
          ))}
          {!block.required ? <p className="text-xs text-muted-foreground">{t("flows.builder.routes.optionalHint")}</p> : null}
        </div>
      ) : null}
      <FieldErrorText errors={errors} />
    </div>
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
  const max = optionCountMax(block)
  const hasImages = block.options.some((option) => isSupportedImage(option.image))

  const setOption = (index: number, patch: Partial<ChoiceOption>) =>
    onChange({ ...block, options: block.options.map((option, i) => (i === index ? { ...option, ...patch } : option)) })

  // El id de la opción es lo que devuelve WhatsApp: sigue al título para que la respuesta sea legible.
  const setTitle = (index: number, title: string) => setOption(index, { id: title, title })

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
          const optionErrors = [
            ...(errors[`options.${index}.title`] ?? []),
            ...(errors[`options.${index}.id`] ?? []),
            ...(errors[`options.${index}.image`] ?? []),
            ...(errors[`options.${index}.alt_text`] ?? []),
          ]
          const id = `${uid}-option-${index}`
          const optionName = option.title || t("flows.builder.block.optionLabel", { n: index + 1 })
          return (
            <li key={index} className="space-y-1">
              <div className="flex items-center gap-2">
                <OptionImageButton
                  value={option.image}
                  maxBytes={OPTION_IMAGE_MAX_BYTES}
                  maxSide={OPTION_IMAGE_MAX_SIDE}
                  label={t(option.image ? "flows.builder.image.replaceFor" : "flows.builder.image.addFor", { option: optionName })}
                  invalid={!!errors[`options.${index}.image`]}
                  onChange={(image) => setOption(index, image ? { image } : { image: null, alt_text: null })}
                  t={t}
                />
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
              {option.image ? (
                <Input
                  value={option.alt_text ?? ""}
                  className="ml-11 h-8 w-[calc(100%-2.75rem)] text-xs"
                  placeholder={t("flows.builder.image.optionAltPlaceholder")}
                  aria-label={t("flows.builder.image.altTextFor", { option: optionName })}
                  onChange={(event) => setOption(index, { alt_text: event.target.value || null })}
                />
              ) : null}
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
      {hasImages && block.type === "dropdown" ? (
        <p className="text-xs text-muted-foreground">{t("flows.builder.image.dropdownLimit", { max })}</p>
      ) : null}
      {hasImages && block.type !== "dropdown" ? (
        <BuilderField id={`${uid}-media-size`} label={t("flows.builder.image.optionSize")} errors={errors.media_size}>
          <Select
            value={block.media_size ?? "regular"}
            onValueChange={(value) => onChange({ ...block, media_size: value as "regular" | "large" })}
          >
            <SelectTrigger id={`${uid}-media-size`} className="w-full sm:w-1/2">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="regular">{t("flows.builder.image.mediaSizes.regular")}</SelectItem>
              <SelectItem value="large">{t("flows.builder.image.mediaSizes.large")}</SelectItem>
            </SelectContent>
          </Select>
        </BuilderField>
      ) : null}
    </div>
  )
}
