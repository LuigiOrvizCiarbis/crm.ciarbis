"use client"

import { useMemo, useState } from "react"

import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Badge } from "@/components/ui/badge"
import { CheckIcon, ChevronDownIcon, XIcon } from "lucide-react"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { cn } from "@/lib/utils"
import { uploadMediaAsset } from "@/lib/api/media-assets"
import type { ContactField } from "@/lib/api/contact-fields"
import type { ProductField } from "@/lib/api/product-fields"
import { RepeaterFieldInput } from "@/components/RepeaterFieldInput"

interface CustomFieldInputProps {
  /** Contact and product fields share the same shape; either works here. */
  field: ContactField | ProductField
  value: unknown
  onChange: (next: unknown) => void
  disabled?: boolean
  className?: string
  /** Abre los pickers desplegables al montar (edición inline en celdas). */
  autoOpen?: boolean
  /** Se llama al cerrar un picker desplegable (para finalizar la edición inline). */
  onPickerClose?: () => void
}

export function CustomFieldInput({ field, value, onChange, disabled, className, autoOpen, onPickerClose }: CustomFieldInputProps) {
  const id = `cf-${field.key}`
  const choices = field.options?.choices ?? []

  const handleText = (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value)
  const handleNumber = (e: React.ChangeEvent<HTMLInputElement>) => {
    const raw = e.target.value
    onChange(raw === "" ? null : Number(raw))
  }

  const labelNode = field.type === "repeater" ? null : (
    <Label htmlFor={id} className="text-sm">
      {field.label}
      {field.is_required ? <span className="text-destructive ml-1">*</span> : null}
    </Label>
  )

  const inputNode = useMemo(() => {
    switch (field.type) {
      case "text":
        return (
          <Input
            id={id}
            type="text"
            value={(value as string | null | undefined) ?? ""}
            onChange={handleText}
            disabled={disabled}
          />
        )
      case "number":
        return (
          <Input
            id={id}
            type="number"
            value={value === null || value === undefined ? "" : String(value)}
            onChange={handleNumber}
            disabled={disabled}
          />
        )
      case "date":
        return (
          <Input
            id={id}
            type="date"
            value={(value as string | null | undefined) ?? ""}
            onChange={handleText}
            disabled={disabled}
          />
        )
      case "boolean":
        return (
          <Switch
            id={id}
            checked={Boolean(value)}
            onCheckedChange={(checked) => onChange(checked)}
            disabled={disabled}
          />
        )
      case "select":
        return (
          <Select
            value={(value as string | null | undefined) ?? ""}
            onValueChange={(next) => onChange(next === "__none__" ? null : next)}
            disabled={disabled}
          >
            <SelectTrigger id={id}>
              <SelectValue placeholder="—" />
            </SelectTrigger>
            <SelectContent>
              {choices.map((choice) => (
                <SelectItem key={choice} value={choice}>
                  {choice}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )
      case "multi_select": {
        const selected = Array.isArray(value) ? (value as string[]) : []
        return (
          <MultiSelectField
            id={id}
            label={field.label}
            choices={choices}
            selected={selected}
            disabled={disabled}
            autoOpen={autoOpen}
            onPickerClose={onPickerClose}
            onChange={(next) => onChange(next)}
          />
        )
      }
      case "email":
        return (
          <Input
            id={id}
            type="email"
            value={(value as string | null | undefined) ?? ""}
            onChange={handleText}
            disabled={disabled}
          />
        )
      case "url":
        return (
          <Input
            id={id}
            type="url"
            value={(value as string | null | undefined) ?? ""}
            onChange={handleText}
            disabled={disabled}
          />
        )
      case "phone":
        return (
          <Input
            id={id}
            type="tel"
            inputMode="tel"
            value={(value as string | null | undefined) ?? ""}
            onChange={handleText}
            disabled={disabled}
          />
        )
      case "file":
        return (
          <FileField
            id={id}
            value={typeof value === "number" ? value : null}
            disabled={disabled}
            onChange={(assetId) => onChange(assetId)}
          />
        )
      case "repeater":
        return (
          <RepeaterFieldInput
            field={field}
            value={value}
            disabled={disabled}
            onChange={onChange}
          />
        )
      default:
        return null
    }
  }, [field.type, field.label, value, choices, disabled, id, autoOpen, onPickerClose])

  return (
    <div className={`space-y-1 ${className ?? ""}`}>
      {labelNode}
      {inputNode}
    </div>
  )
}

interface FileFieldProps {
  id: string
  value: number | null
  disabled?: boolean
  onChange: (assetId: number | null) => void
}

// El valor de un campo tipo archivo es un media_asset_id: el PDF se sube a la
// app, que lo aloja y lo sirve por una URL pública (la que Meta descarga). Al
// subir uno nuevo se reemplaza el id guardado.
function FileField({ id, value, disabled, onChange }: FileFieldProps) {
  const [uploading, setUploading] = useState(false)
  const [name, setName] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const handleFile = async (file: File | undefined) => {
    if (!file) return
    setUploading(true)
    setError(null)
    try {
      const asset = await uploadMediaAsset(file)
      setName(asset.name)
      onChange(asset.id)
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error al subir el archivo")
    } finally {
      setUploading(false)
    }
  }

  return (
    <div className="space-y-1">
      <Input
        id={id}
        type="file"
        accept="application/pdf"
        disabled={disabled || uploading}
        onChange={(e) => void handleFile(e.target.files?.[0])}
      />
      <p className="text-xs text-muted-foreground">
        {uploading
          ? "Subiendo…"
          : error
            ? <span className="text-destructive">{error}</span>
            : name
              ? name
              : value
                ? "Archivo cargado"
                : "Ningún archivo"}
      </p>
    </div>
  )
}

interface MultiSelectFieldProps {
  id: string
  label: string
  choices: string[]
  selected: string[]
  disabled?: boolean
  autoOpen?: boolean
  onPickerClose?: () => void
  onChange: (next: string[]) => void
}

function MultiSelectField({ id, label, choices, selected, disabled, autoOpen, onPickerClose, onChange }: MultiSelectFieldProps) {
  const [open, setOpen] = useState(Boolean(autoOpen))

  const handleOpenChange = (next: boolean) => {
    setOpen(next)
    if (!next) onPickerClose?.()
  }

  const toggle = (choice: string) => {
    const next = selected.includes(choice)
      ? selected.filter((c) => c !== choice)
      : Array.from(new Set([...selected, choice]))
    onChange(next)
  }

  const remove = (choice: string, e: React.MouseEvent) => {
    e.stopPropagation()
    onChange(selected.filter((c) => c !== choice))
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <button
          id={id}
          type="button"
          role="combobox"
          aria-expanded={open}
          aria-label={label || undefined}
          disabled={disabled}
          className={cn(
            "flex min-h-9 w-full items-center gap-1.5 rounded-md border border-input bg-transparent px-2.5 py-1.5 text-left text-sm shadow-xs transition-[color,box-shadow] outline-none",
            "hover:bg-muted/40 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50",
            "disabled:cursor-not-allowed disabled:opacity-50",
          )}
        >
          <span className="flex flex-1 flex-wrap items-center gap-1 overflow-hidden">
            {selected.length === 0 ? (
              <span className="text-muted-foreground">Seleccionar…</span>
            ) : (
              selected.map((choice) => (
                <Badge
                  key={choice}
                  variant="secondary"
                  className="h-5 gap-1 rounded-sm px-1.5 font-normal"
                >
                  <span className="truncate">{choice}</span>
                  <span
                    role="button"
                    tabIndex={-1}
                    aria-label={`Quitar ${choice}`}
                    onClick={(e) => remove(choice, e)}
                    className="-mr-0.5 flex size-3.5 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-foreground/10 hover:text-foreground"
                  >
                    <XIcon className="size-3" />
                  </span>
                </Badge>
              ))
            )}
          </span>
          <ChevronDownIcon className="size-4 shrink-0 text-muted-foreground" />
        </button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className="w-(--radix-popover-trigger-width) min-w-56 p-0"
        onOpenAutoFocus={(e) => choices.length > 8 ? undefined : e.preventDefault()}
      >
        <Command>
          {choices.length > 8 ? <CommandInput placeholder="Buscar…" className="h-9" /> : null}
          <CommandList>
            <CommandEmpty>Sin resultados</CommandEmpty>
            <CommandGroup>
              {choices.map((choice) => {
                const checked = selected.includes(choice)
                return (
                  <CommandItem
                    key={choice}
                    value={choice}
                    onSelect={() => toggle(choice)}
                    className="gap-2.5"
                  >
                    <span
                      aria-hidden
                      className={cn(
                        "flex size-4 shrink-0 items-center justify-center rounded-[4px] border transition-all duration-150",
                        checked
                          ? "border-primary bg-primary text-primary-foreground"
                          : "border-input bg-input/30 text-transparent",
                      )}
                    >
                      <CheckIcon className="size-3.5" />
                    </span>
                    <span className="truncate">{choice}</span>
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
