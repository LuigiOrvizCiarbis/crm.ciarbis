"use client"

import { useRef, useState, type ComponentProps, type ReactNode } from "react"
import { Bold, Code, Italic, Link, List, ListOrdered, Quote, Smile, Strikethrough } from "lucide-react"

import { EmojiPicker } from "@/components/chat/EmojiPicker"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Textarea } from "@/components/ui/textarea"
import { useTranslation } from "@/hooks/useTranslation"
import { FORMAT_MARKERS, insertAt, toggleWrap, togglePrefix, type RichTextDialect, type TextEdit } from "@/lib/rich-text"
import { cn } from "@/lib/utils"

type Field = HTMLTextAreaElement | HTMLInputElement

/** Aplica un cambio al campo y deja la selección donde corresponde; uno que pasa el máximo no se aplica. */
function useFieldEdits<T extends Field>(value: string, onValueChange: (value: string) => void, maxLength?: number) {
  const ref = useRef<T>(null)
  const selection = () => {
    const field = ref.current
    return { start: field?.selectionStart ?? value.length, end: field?.selectionEnd ?? value.length }
  }
  const apply = (edit: TextEdit) => {
    if (maxLength !== undefined && edit.value.length > maxLength) return
    onValueChange(edit.value)
    requestAnimationFrame(() => {
      ref.current?.focus()
      ref.current?.setSelectionRange(edit.selectionStart, edit.selectionEnd)
    })
  }
  return { ref, selection, apply }
}

function ToolButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      // Sin esto el botón se lleva el foco y la selección del texto se pierde.
      onMouseDown={(event) => event.preventDefault()}
      onClick={onClick}
      className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
    >
      {children}
    </button>
  )
}

function EmojiButton({ onSelect, disabled }: { onSelect: (emoji: string) => void; disabled?: boolean }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={t("richText.emoji")}
          title={t("richText.emoji")}
          disabled={disabled}
          className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
        >
          <Smile className="size-4" aria-hidden />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-80 p-2">
        <EmojiPicker
          onSelect={(emoji) => {
            onSelect(emoji)
            setOpen(false)
          }}
        />
      </PopoverContent>
    </Popover>
  )
}

type FormattedTextareaProps = Omit<ComponentProps<"textarea">, "value" | "onChange"> & {
  value: string
  onValueChange: (value: string) => void
  /** Qué formato entiende el destino: un mensaje de WhatsApp o un texto de pantalla del formulario. */
  dialect: RichTextDialect
}

/** Textarea con botones de formato y emojis; escribe las marcas que el destino interpreta. */
export function FormattedTextarea({ value, onValueChange, dialect, maxLength, disabled, className, ref: outerRef, ...props }: FormattedTextareaProps) {
  const { t } = useTranslation()
  const { ref, selection, apply } = useFieldEdits<HTMLTextAreaElement>(value, onValueChange, maxLength)
  const markers = FORMAT_MARKERS[dialect]

  const wrap = (marker: string) => {
    const { start, end } = selection()
    apply(toggleWrap(value, start, end, marker))
  }
  const prefix = (make: (index: number) => string, pattern: RegExp) => {
    const { start, end } = selection()
    apply(togglePrefix(value, start, end, make, pattern))
  }
  const link = () => {
    const { start, end } = selection()
    const text = value.slice(start, end).trim() || t("richText.linkText")
    const edit = insertAt(value, start, end, `[${text}](https://)`)
    // El cursor queda después de «https://» para escribir la dirección.
    apply({ ...edit, selectionStart: edit.selectionEnd - 1, selectionEnd: edit.selectionEnd - 1 })
  }

  return (
    <div
      className={cn(
        "rounded-md border border-input focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 has-[textarea[aria-invalid=true]]:border-destructive",
        disabled && "opacity-50",
      )}
    >
      <div className="flex flex-wrap items-center gap-0.5 border-b border-input px-1 py-0.5" role="toolbar" aria-label={t("richText.toolbar")}>
        {markers.bold ? (
          <ToolButton label={t("richText.bold")} disabled={disabled} onClick={() => wrap(markers.bold!)}>
            <Bold className="size-4" aria-hidden />
          </ToolButton>
        ) : null}
        {markers.italic ? (
          <ToolButton label={t("richText.italic")} disabled={disabled} onClick={() => wrap(markers.italic!)}>
            <Italic className="size-4" aria-hidden />
          </ToolButton>
        ) : null}
        {markers.strike ? (
          <ToolButton label={t("richText.strike")} disabled={disabled} onClick={() => wrap(markers.strike!)}>
            <Strikethrough className="size-4" aria-hidden />
          </ToolButton>
        ) : null}
        {markers.code ? (
          <ToolButton label={t("richText.code")} disabled={disabled} onClick={() => wrap(markers.code!)}>
            <Code className="size-4" aria-hidden />
          </ToolButton>
        ) : null}
        <ToolButton label={t("richText.bullets")} disabled={disabled} onClick={() => prefix(() => "- ", /^- /)}>
          <List className="size-4" aria-hidden />
        </ToolButton>
        <ToolButton label={t("richText.numbers")} disabled={disabled} onClick={() => prefix((index) => `${index + 1}. `, /^\d+\. /)}>
          <ListOrdered className="size-4" aria-hidden />
        </ToolButton>
        {dialect === "whatsapp" ? (
          <ToolButton label={t("richText.quote")} disabled={disabled} onClick={() => prefix(() => "> ", /^> /)}>
            <Quote className="size-4" aria-hidden />
          </ToolButton>
        ) : (
          <ToolButton label={t("richText.link")} disabled={disabled} onClick={link}>
            <Link className="size-4" aria-hidden />
          </ToolButton>
        )}
        <span className="ml-auto">
          <EmojiButton disabled={disabled} onSelect={(emoji) => apply(insertAt(value, selection().start, selection().end, emoji))} />
        </span>
      </div>
      <Textarea
        {...props}
        ref={(node) => {
          ref.current = node
          // Quien lo usa puede insertar texto en el cursor (por ejemplo, una variable).
          if (typeof outerRef === "function") outerRef(node)
          else if (outerRef) outerRef.current = node
        }}
        value={value}
        maxLength={maxLength}
        disabled={disabled}
        onChange={(event) => onValueChange(event.target.value)}
        className={cn("rounded-t-none border-0 shadow-none focus-visible:ring-0", className)}
      />
    </div>
  )
}

type EmojiInputProps = Omit<ComponentProps<"input">, "value" | "onChange"> & {
  value: string
  onValueChange: (value: string) => void
}

/** Input de una línea con botón de emojis (títulos, encabezados y pies: no admiten formato). */
export function EmojiInput({ value, onValueChange, maxLength, disabled, className, ...props }: EmojiInputProps) {
  const { ref, selection, apply } = useFieldEdits<HTMLInputElement>(value, onValueChange, maxLength)
  return (
    <div className="relative">
      <Input
        {...props}
        ref={ref}
        value={value}
        maxLength={maxLength}
        disabled={disabled}
        onChange={(event) => onValueChange(event.target.value)}
        className={cn("pr-9", className)}
      />
      <span className="absolute right-1 top-1/2 -translate-y-1/2">
        <EmojiButton disabled={disabled} onSelect={(emoji) => apply(insertAt(value, selection().start, selection().end, emoji))} />
      </span>
    </div>
  )
}
