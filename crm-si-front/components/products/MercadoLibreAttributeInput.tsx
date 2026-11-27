"use client"

import { useState } from "react"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"
import { useTranslation } from "@/hooks/useTranslation"
import type { MercadoLibreAttribute, MercadoLibreAttributeValue } from "@/lib/api/mercadolibre"

const CUSTOM_OPTION = "__custom__"

interface MercadoLibreAttributeInputProps {
  attribute: MercadoLibreAttribute
  value: MercadoLibreAttributeValue | undefined
  /** `null` borra el valor. */
  onChange: (value: MercadoLibreAttributeValue | null) => void
  invalid: boolean
  error?: string
}

export function MercadoLibreAttributeInput({
  attribute,
  value,
  onChange,
  invalid,
  error,
}: MercadoLibreAttributeInputProps) {
  const { t } = useTranslation()
  const inputId = `ml-attr-${attribute.id}`
  const invalidClass = invalid ? "border-destructive focus-visible:ring-destructive/30" : undefined

  return (
    <div className="space-y-1.5">
      <Label htmlFor={inputId} className={cn(invalid && "text-destructive")}>
        {attribute.name}
        {attribute.required && <span className="text-destructive"> *</span>}
      </Label>
      <AttributeControl
        inputId={inputId}
        attribute={attribute}
        value={value}
        onChange={onChange}
        invalidClass={invalidClass}
      />
      {error ? (
        <p className="text-xs text-destructive">{error}</p>
      ) : invalid ? (
        <p className="text-xs text-destructive">{t("products.mercadolibre.requiredEmpty")}</p>
      ) : attribute.hint ? (
        <p className="text-xs text-muted-foreground">{attribute.hint}</p>
      ) : null}
    </div>
  )
}

interface ControlProps {
  inputId: string
  attribute: MercadoLibreAttribute
  value: MercadoLibreAttributeValue | undefined
  onChange: (value: MercadoLibreAttributeValue | null) => void
  invalidClass?: string
}

function AttributeControl(props: ControlProps) {
  const { attribute } = props
  const type = attribute.value_type

  if ((type === "list" || type === "boolean") && attribute.values.length > 0) {
    return <ListControl {...props} />
  }
  if (type === "string" && attribute.values.length > 0) {
    return <SuggestionsControl {...props} />
  }
  if (type === "number") {
    return <NumberControl {...props} />
  }
  if (type === "number_unit") {
    return <NumberUnitControl {...props} />
  }
  return <TextControl {...props} />
}

/** Valor elegido de la lista de ML: va con value_id. */
function ListControl({ inputId, attribute, value, onChange, invalidClass }: ControlProps) {
  const { t } = useTranslation()
  const selected =
    attribute.values.find((option) => option.id === value?.value_id) ??
    attribute.values.find((option) => option.name === value?.value_name)

  return (
    <Select
      value={selected?.id ?? ""}
      onValueChange={(id) => {
        const option = attribute.values.find((item) => item.id === id)
        if (option) onChange({ id: attribute.id, value_id: option.id, value_name: option.name })
      }}
    >
      <SelectTrigger id={inputId} className={cn("w-full", invalidClass)}>
        <SelectValue placeholder={t("products.mercadolibre.selectValue")} />
      </SelectTrigger>
      <SelectContent>
        {attribute.values.map((option) => (
          <SelectItem key={option.id} value={option.id}>
            {option.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

/** Sugerencias de ML más texto libre: value_id solo cuando se eligió una sugerencia. */
function SuggestionsControl({ inputId, attribute, value, onChange, invalidClass }: ControlProps) {
  const { t } = useTranslation()
  const selected =
    attribute.values.find((option) => value?.value_id && option.id === value.value_id) ??
    attribute.values.find((option) => option.name === value?.value_name)
  const [custom, setCustom] = useState(() => Boolean(value?.value_name) && !selected)
  const showInput = custom && !selected

  return (
    <div className="space-y-2">
      <Select
        value={selected?.id ?? (showInput ? CUSTOM_OPTION : "")}
        onValueChange={(id) => {
          if (id === CUSTOM_OPTION) {
            setCustom(true)
            onChange(null)
            return
          }
          const option = attribute.values.find((item) => item.id === id)
          if (option) {
            setCustom(false)
            onChange({ id: attribute.id, value_id: option.id, value_name: option.name })
          }
        }}
      >
        <SelectTrigger id={inputId} className={cn("w-full", invalidClass)}>
          <SelectValue placeholder={t("products.mercadolibre.selectValue")} />
        </SelectTrigger>
        <SelectContent>
          {attribute.values.map((option) => (
            <SelectItem key={option.id} value={option.id}>
              {option.name}
            </SelectItem>
          ))}
          <SelectItem value={CUSTOM_OPTION}>{t("products.mercadolibre.customValue")}</SelectItem>
        </SelectContent>
      </Select>
      {showInput && (
        <Input
          value={value?.value_name ?? ""}
          maxLength={attribute.max_length ?? undefined}
          placeholder={t("products.mercadolibre.freeTextPlaceholder")}
          className={invalidClass}
          onChange={(event) => {
            const text = event.target.value
            onChange(text ? { id: attribute.id, value_id: null, value_name: text } : null)
          }}
        />
      )}
    </div>
  )
}

function NumberControl({ inputId, attribute, value, onChange, invalidClass }: ControlProps) {
  return (
    <Input
      id={inputId}
      type="number"
      inputMode="decimal"
      value={value?.value_name ?? ""}
      className={invalidClass}
      onChange={(event) => {
        const text = event.target.value
        onChange(text ? { id: attribute.id, value_id: null, value_name: text } : null)
      }}
    />
  )
}

function parseNumberUnit(valueName: string | undefined): { amount: string; unit: string } {
  const match = valueName?.trim().match(/^(-?[\d.,]+)\s*(.*)$/)
  if (!match) return { amount: "", unit: "" }
  return { amount: match[1].replace(",", "."), unit: match[2] }
}

/** value_name = "<número> <unidad>", p. ej. "10 cm". */
function NumberUnitControl({ inputId, attribute, value, onChange, invalidClass }: ControlProps) {
  const { t } = useTranslation()
  const parsed = parseNumberUnit(value?.value_name)
  const [fallbackUnit, setFallbackUnit] = useState(
    () => parsed.unit || attribute.allowed_units[0]?.id || "",
  )
  const unit = parsed.unit || fallbackUnit

  const emit = (amount: string, nextUnit: string) => {
    if (!amount) {
      onChange(null)
      return
    }
    onChange({
      id: attribute.id,
      value_id: null,
      value_name: nextUnit ? `${amount} ${nextUnit}` : amount,
    })
  }

  return (
    <div className="flex gap-2">
      <Input
        id={inputId}
        type="number"
        inputMode="decimal"
        value={parsed.amount}
        className={cn("flex-1", invalidClass)}
        onChange={(event) => emit(event.target.value, unit)}
      />
      {attribute.allowed_units.length > 0 && (
        <Select
          value={unit}
          onValueChange={(nextUnit) => {
            setFallbackUnit(nextUnit)
            emit(parsed.amount, nextUnit)
          }}
        >
          <SelectTrigger className="w-32" aria-label={t("products.mercadolibre.unit")}>
            <SelectValue placeholder={t("products.mercadolibre.unit")} />
          </SelectTrigger>
          <SelectContent>
            {attribute.allowed_units.map((option) => (
              <SelectItem key={option.id} value={option.id}>
                {option.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </div>
  )
}

function TextControl({ inputId, attribute, value, onChange, invalidClass }: ControlProps) {
  return (
    <Input
      id={inputId}
      value={value?.value_name ?? ""}
      maxLength={attribute.max_length ?? undefined}
      className={invalidClass}
      onChange={(event) => {
        const text = event.target.value
        onChange(text ? { id: attribute.id, value_id: null, value_name: text } : null)
      }}
    />
  )
}
