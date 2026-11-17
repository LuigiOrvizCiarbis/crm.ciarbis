"use client"

import { useState } from "react"
import { FileUp, Loader2 } from "lucide-react"

import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from "@/components/ui/select"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import type { TemplateParameter, WhatsAppTemplate } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { uploadMediaAsset, type MediaAsset } from "@/lib/api/media-assets"
import {
  ANSWER_PREFIX,
  CONTACT_PATHS,
  CUSTOM_DATA_PREFIX,
  NO_PARAMETER_ERRORS,
  answerLabel,
  parameterIssue,
  textContactFields,
  type ParameterContext,
  type ParameterErrors,
} from "@/lib/template-parameters"
import { templateHeaderMediaFormat } from "@/lib/whatsapp-templates"

const LITERAL = "__literal"

const HEADER_ACCEPT: Record<"IMAGE" | "VIDEO" | "DOCUMENT", string> = {
  IMAGE: "image/jpeg,image/png",
  VIDEO: "video/mp4",
  DOCUMENT: "application/pdf",
}

interface TemplateParametersEditorProps {
  /** Sin plantilla (variables de un texto propio) no hay encabezado de media. */
  template?: WhatsAppTemplate
  /** Filas de `templateParameterRows`: encabezado de media primero, después una por variable. */
  parameters: TemplateParameter[]
  onChange: (parameters: TemplateParameter[]) => void
  /** Con `answers`, también se pueden usar las respuestas del formulario (sólo en el cierre). */
  context: ParameterContext
  errors?: ParameterErrors
  /** Prefijo único de los ids de los campos. */
  idPrefix: string
  disabled?: boolean
}

/** Una fila por variable de la plantilla: de dónde sale el valor y qué mandar si queda vacío. */
export function TemplateParametersEditor({
  template,
  parameters,
  onChange,
  context,
  errors = NO_PARAMETER_ERRORS,
  idPrefix,
  disabled = false,
}: TemplateParametersEditorProps) {
  const { t } = useTranslation()
  const headerFormat = templateHeaderMediaFormat(template)

  if (parameters.length === 0) {
    return <p className="text-xs text-muted-foreground">{t("flows.journey.params.none")}</p>
  }

  const update = (index: number, next: TemplateParameter) =>
    onChange(parameters.map((item, itemIndex) => (itemIndex === index ? next : item)))

  return (
    <div className="space-y-3">
      {errors.general.length > 0 ? <p className="text-xs text-destructive">{errors.general.join(" ")}</p> : null}
      {parameters.map((parameter, index) =>
        parameter.component === "header" && headerFormat ? (
          <HeaderRow
            key={`header-${index}`}
            parameter={parameter}
            format={headerFormat}
            errors={errors.rows[index]}
            id={`${idPrefix}-header`}
            disabled={disabled}
            onChange={(next) => update(index, next)}
          />
        ) : (
          <BodyRow
            key={`body-${parameter.name ?? index}`}
            parameter={parameter}
            context={context}
            errors={errors.rows[index]}
            id={`${idPrefix}-${index}`}
            disabled={disabled}
            onChange={(next) => update(index, next)}
          />
        ),
      )}
    </div>
  )
}

function RowErrors({ messages, issue }: { messages?: string[]; issue: string | null }) {
  if (messages && messages.length > 0) return <p className="text-xs text-destructive">{messages.join(" ")}</p>
  if (issue) return <p className="text-xs text-muted-foreground">{issue}</p>
  return null
}

function BodyRow({
  parameter,
  context,
  errors,
  id,
  disabled,
  onChange,
}: {
  parameter: TemplateParameter
  context: ParameterContext
  errors?: string[]
  id: string
  disabled: boolean
  onChange: (next: TemplateParameter) => void
}) {
  const { t } = useTranslation()
  const customFields = textContactFields(context.contactFields)
  const value = parameter.source === "literal" ? LITERAL : (parameter.path ?? "")
  const knownPaths = new Set<string>([
    LITERAL,
    ...CONTACT_PATHS,
    ...context.answers.map((field) => `${ANSWER_PREFIX}${field.key}`),
    ...customFields.map((field) => `${CUSTOM_DATA_PREFIX}${field.key}`),
  ])
  const issue = parameterIssue(parameter)

  const choose = (next: string) => {
    const base = { component: "body" as const, name: parameter.name }
    if (next === LITERAL) onChange({ ...base, source: "literal", value: "" })
    else onChange({ ...base, source: "field", path: next, fallback: parameter.fallback })
  }

  return (
    <div className="space-y-2 rounded-md bg-muted/40 p-2.5">
      <div className="grid gap-2 sm:grid-cols-[minmax(0,7rem)_minmax(0,1fr)] sm:items-center">
        <label htmlFor={`${id}-source`} className="truncate font-mono text-xs text-muted-foreground" title={`{{${parameter.name}}}`}>
          {`{{${parameter.name}}}`}
        </label>
        <Select value={value} onValueChange={choose} disabled={disabled}>
          <SelectTrigger id={`${id}-source`} className="h-9 w-full" aria-invalid={Boolean(errors?.length)}>
            <SelectValue placeholder={t("flows.journey.params.selectSource")} />
          </SelectTrigger>
          <SelectContent>
            {context.answers.length > 0 ? (
              <SelectGroup>
                <SelectLabel>{t("flows.journey.params.answers")}</SelectLabel>
                {context.answers.map((field) => (
                  <SelectItem key={field.key} value={`${ANSWER_PREFIX}${field.key}`}>
                    {t("flows.journey.params.answer", { label: answerLabel(field, t) })}
                  </SelectItem>
                ))}
              </SelectGroup>
            ) : null}
            <SelectGroup>
              <SelectLabel>{t("flows.journey.params.contactGroup")}</SelectLabel>
              {CONTACT_PATHS.map((path) => (
                <SelectItem key={path} value={path}>
                  {t(`flows.journey.params.contact.${path.slice("contact.".length)}`)}
                </SelectItem>
              ))}
              {customFields.map((field) => (
                <SelectItem key={field.key} value={`${CUSTOM_DATA_PREFIX}${field.key}`}>
                  {t("flows.journey.params.contactField", { label: field.label })}
                </SelectItem>
              ))}
            </SelectGroup>
            <SelectGroup>
              <SelectLabel>{t("flows.journey.params.otherGroup")}</SelectLabel>
              <SelectItem value={LITERAL}>{t("flows.journey.params.literal")}</SelectItem>
            </SelectGroup>
            {value && !knownPaths.has(value) ? <SelectItem value={value}>{value}</SelectItem> : null}
          </SelectContent>
        </Select>
      </div>
      {parameter.source === "literal" ? (
        <Input
          value={parameter.value ?? ""}
          disabled={disabled}
          aria-label={t("flows.journey.params.literalValue", { name: parameter.name ?? "" })}
          placeholder={t("flows.journey.params.literalPlaceholder")}
          onChange={(event) => onChange({ ...parameter, value: event.target.value })}
        />
      ) : (
        <Input
          value={parameter.fallback ?? ""}
          disabled={disabled}
          aria-label={t("flows.journey.params.fallbackFor", { name: parameter.name ?? "" })}
          placeholder={t("flows.journey.params.fallback")}
          onChange={(event) => onChange({ ...parameter, fallback: event.target.value })}
        />
      )}
      <RowErrors messages={errors} issue={issue === "value" ? t("flows.journey.params.missingValue") : null} />
    </div>
  )
}

/** Archivo del espacio o URL https para un encabezado de media; también lo usa la imagen del cierre de texto. */
export function HeaderRow({
  parameter,
  format,
  errors,
  id,
  disabled,
  label,
  onChange,
  onUploaded,
}: {
  parameter: TemplateParameter
  format: "IMAGE" | "VIDEO" | "DOCUMENT"
  errors?: string[]
  id: string
  disabled: boolean
  /** Reemplaza el rótulo «Encabezado: …». */
  label?: string
  onChange: (next: TemplateParameter) => void
  /** Recibe el archivo recién subido, por ejemplo para mostrarlo en la vista previa. */
  onUploaded?: (asset: MediaAsset) => void
}) {
  const { t } = useTranslation()
  const [uploading, setUploading] = useState(false)
  const [uploadedName, setUploadedName] = useState<string | null>(null)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const mode = parameter.source === "literal" ? "url" : "file"
  const issue = parameterIssue(parameter)

  const upload = async (file: File | undefined) => {
    if (!file) return
    setUploading(true)
    setUploadError(null)
    try {
      const asset = await uploadMediaAsset(file)
      setUploadedName(asset.name)
      onChange({ component: "header", source: "media_asset", media_asset_id: asset.id })
      onUploaded?.(asset)
    } catch (error) {
      setUploadError(error instanceof Error ? error.message : t("flows.journey.params.uploadError"))
    } finally {
      setUploading(false)
    }
  }

  return (
    <div className="space-y-2 rounded-md bg-muted/40 p-2.5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-xs font-medium text-muted-foreground">
          {label ?? t("flows.journey.params.header", { format: t(`flows.journey.params.formats.${format}`) })}
        </span>
        <ToggleGroup
          type="single"
          variant="outline"
          size="sm"
          value={mode}
          disabled={disabled}
          aria-label={t("flows.journey.params.headerSource")}
          onValueChange={(next) => {
            if (next === "url") onChange({ component: "header", source: "literal", value: "" })
            else if (next === "file") onChange({ component: "header", source: "media_asset" })
          }}
        >
          <ToggleGroupItem value="file" className="px-3 text-xs">
            {t("flows.journey.params.file")}
          </ToggleGroupItem>
          <ToggleGroupItem value="url" className="px-3 text-xs">
            URL
          </ToggleGroupItem>
        </ToggleGroup>
      </div>
      {mode === "file" ? (
        <label
          htmlFor={`${id}-file`}
          className="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-border bg-background px-3 py-2 text-xs text-muted-foreground focus-within:ring-[3px] focus-within:ring-ring/50 hover:bg-muted/40"
        >
          {uploading ? <Loader2 className="size-4 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden /> : <FileUp className="size-4 shrink-0" aria-hidden />}
          <span className="min-w-0 truncate">
            {uploading
              ? t("flows.journey.params.uploading")
              : (uploadedName ?? (parameter.media_asset_id ? t("flows.journey.params.fileLoaded") : t("flows.journey.params.chooseFile")))}
          </span>
          <input
            id={`${id}-file`}
            type="file"
            className="sr-only"
            accept={HEADER_ACCEPT[format]}
            disabled={disabled || uploading}
            onChange={(event) => {
              void upload(event.target.files?.[0])
              // Vaciarlo deja volver a elegir el mismo archivo después de un error.
              event.target.value = ""
            }}
          />
        </label>
      ) : (
        <Input
          type="url"
          inputMode="url"
          value={parameter.value ?? ""}
          disabled={disabled}
          placeholder="https://"
          aria-label={t("flows.journey.params.headerUrl")}
          aria-invalid={issue === "url" && Boolean(parameter.value?.trim())}
          onChange={(event) => onChange({ component: "header", source: "literal", value: event.target.value })}
        />
      )}
      {uploadError ? <p className="text-xs text-destructive">{uploadError}</p> : null}
      <RowErrors
        messages={errors}
        issue={
          issue === "file"
            ? t("flows.journey.params.missingFile")
            : issue === "url"
              ? t("flows.journey.params.missingUrl")
              : null
        }
      />
    </div>
  )
}
