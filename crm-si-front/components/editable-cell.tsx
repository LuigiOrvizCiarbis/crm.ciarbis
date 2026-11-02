"use client"

import type React from "react"
import { useState, useEffect, useRef, type KeyboardEvent } from "react"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"

type CellType = "text" | "select"

interface EditableCellProps {
  value: string
  type: CellType
  options?: Array<{ value: string; label: string }>
  onSave: (value: string) => void
  onCancel?: () => void
  isEditing: boolean
  onEditStart: () => void
  validation?: (value: string) => string | null
  className?: string
  placeholder?: string
  renderDisplay?: (value: string) => React.ReactNode
}

export function EditableCell({
  value,
  type,
  options = [],
  onSave,
  onCancel,
  isEditing,
  onEditStart,
  validation,
  className = "",
  placeholder,
  renderDisplay,
}: EditableCellProps) {
  const [editValue, setEditValue] = useState<string>(value ?? "")
  const [error, setError] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    setEditValue(value ?? "")
    setError(null)
  }, [value, isEditing])

  useEffect(() => {
    if (isEditing && type === "text") {
      inputRef.current?.focus()
      inputRef.current?.select()
    }
  }, [isEditing, type])

  const handleSave = (): void => {
    if (validation) {
      const validationError = validation(editValue)
      if (validationError) {
        setError(validationError)
        return
      }
    }
    if (editValue !== (value ?? "")) {
      onSave(editValue)
    } else {
      onCancel?.()
    }
    setError(null)
  }

  const handleCancel = (): void => {
    setEditValue(value ?? "")
    setError(null)
    onCancel?.()
  }

  const handleKeyDown = (e: KeyboardEvent): void => {
    if (e.key === "Enter") {
      e.preventDefault()
      handleSave()
    } else if (e.key === "Escape") {
      e.preventDefault()
      handleCancel()
    }
  }

  if (!isEditing) {
    return (
      <div
        role="button"
        tabIndex={0}
        className={`cursor-text hover:bg-muted/40 rounded px-2 py-1 min-h-[32px] flex items-center transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 ${className}`}
        onClick={onEditStart}
        onKeyDown={(e) => {
          if (e.target !== e.currentTarget) return
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault()
            onEditStart()
          }
        }}
        title="Hacé clic para editar"
      >
        {renderDisplay ? renderDisplay(value) : <span className={value ? "" : "text-muted-foreground"}>{value || "—"}</span>}
      </div>
    )
  }

  if (type === "select") {
    return (
      <Select
        defaultOpen
        // Cerrar el desplegable sin elegir (Escape, clic afuera) termina la edición.
        onOpenChange={(open) => {
          if (!open) onCancel?.()
        }}
        value={editValue}
        onValueChange={(val) => {
          setEditValue(val)
          if (val !== (value ?? "")) {
            onSave(val)
          } else {
            onCancel?.()
          }
        }}
      >
        <SelectTrigger className={error ? "border-destructive" : ""}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {options.map((opt) => (
            <SelectItem key={opt.value} value={opt.value}>
              {opt.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  return (
    <div className="relative">
      <Input
        ref={inputRef}
        type="text"
        value={editValue}
        placeholder={placeholder}
        onChange={(e) => setEditValue(e.target.value)}
        onKeyDown={handleKeyDown}
        onBlur={handleSave}
        className={`h-8 ${error ? "border-destructive" : ""}`}
      />
      {error && <span className="text-xs text-destructive absolute -bottom-5 left-0 z-10 bg-background px-1">{error}</span>}
    </div>
  )
}
