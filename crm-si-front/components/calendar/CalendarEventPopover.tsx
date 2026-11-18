"use client"

import { useId, type ComponentProps, type ReactNode } from "react"
import type { LucideIcon } from "lucide-react"
import { X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Popover, PopoverAnchor, PopoverContent } from "@/components/ui/popover"
import { cn } from "@/lib/utils"

/** "Sábado, 14 de noviembre" (con año solo si no es el actual), como Google Calendar. */
export function formatEventDay(date: Date, language: string): string {
  const locale = language === "en" ? "en-US" : "es-AR"
  const label = new Intl.DateTimeFormat(locale, {
    weekday: "long",
    day: "numeric",
    month: "long",
    ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: "numeric" }),
  }).format(date)
  return label.charAt(0).toLocaleUpperCase(locale) + label.slice(1)
}

/** 24 h en español, como el resto del calendario del CRM; en inglés, el formato local. */
export function formatEventTime(date: Date, language: string): string {
  return language === "en"
    ? new Intl.DateTimeFormat("en-US", { hour: "numeric", minute: "2-digit" }).format(date)
    : new Intl.DateTimeFormat("es-AR", { hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(date)
}

/** "Sábado, 14 de noviembre · 16:16 – 16:46"; sin `end`, solo la hora de inicio. */
export function formatEventWhen(start: Date, end: Date | null, language: string): string {
  const day = formatEventDay(start, language)
  const startTime = formatEventTime(start, language)
  if (!end) return `${day} · ${startTime}`
  if (end.toDateString() === start.toDateString()) return `${day} · ${startTime} – ${formatEventTime(end, language)}`
  return `${day}, ${startTime} – ${formatEventDay(end, language)}, ${formatEventTime(end, language)}`
}

/** Acción de ícono de la barra superior. Siempre con nombre accesible y tooltip nativo. */
export function CalendarEventAction({ label, className, ...props }: ComponentProps<typeof Button> & { label: string }) {
  return <Button type="button" variant="ghost" size="icon" aria-label={label} title={label} className={cn("size-10", className)} {...props} />
}

/** Fila de detalle: ícono en la columna del cuadro de color y el contenido alineado con el título. */
export function CalendarEventRow({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[1.25rem_minmax(0,1fr)] items-start gap-x-4 text-sm">
      <Icon aria-hidden="true" className="mt-0.5 size-5 text-muted-foreground" />
      <div className="min-w-0">
        <span className="sr-only">{label}: </span>
        {children}
      </div>
    </div>
  )
}

export interface CalendarEventPopoverProps {
  /** Elemento del calendario que se clickeó: el popover se ancla a él y le devuelve el foco al cerrar. */
  anchor: HTMLElement
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Impide cerrarlo mientras una acción está en curso. */
  locked?: boolean
  closeLabel: string
  actions?: ReactNode
  swatchClassName: string
  title: string
  when: string
  subtitle?: string
  children?: ReactNode
}

// Ancho de la tarjeta: 26rem o la pantalla menos el margen de colisión.
const CARD_WIDTH_PX = 416

/**
 * Al costado del evento si entra (como Google Calendar); si no, debajo. Radix
 * solo invierte al lado opuesto, así que un chip angosto en mobile o una fila
 * de ancho completo en la vista Día dejaría la tarjeta fuera de pantalla.
 */
function popoverSide(anchor: HTMLElement): "right" | "left" | "bottom" {
  const rect = anchor.getBoundingClientRect()
  const needed = Math.min(CARD_WIDTH_PX, window.innerWidth - 16) + 16
  if (window.innerWidth - rect.right >= needed) return "right"
  if (rect.left >= needed) return "left"
  return "bottom"
}

/**
 * Tarjeta de detalle de un evento del calendario, al estilo Google Calendar:
 * acciones arriba a la derecha, cuadro de color + título + horario, y filas de
 * detalle con ícono. Es un popover anclado al evento, no un modal: no tapa el
 * calendario y se cierra al hacer click afuera o con Escape.
 */
export function CalendarEventPopover({
  anchor,
  open,
  onOpenChange,
  locked = false,
  closeLabel,
  actions,
  swatchClassName,
  title,
  when,
  subtitle,
  children,
}: CalendarEventPopoverProps) {
  const titleId = useId()
  const side = popoverSide(anchor)

  return (
    <Popover open={open} onOpenChange={(next) => { if (next || !locked) onOpenChange(next) }}>
      <PopoverAnchor virtualRef={{ current: anchor }} />
      <PopoverContent
        side={side}
        align="start"
        sideOffset={8}
        collisionPadding={8}
        aria-labelledby={titleId}
        className="max-h-[min(36rem,calc(100dvh-1rem))] w-[min(26rem,calc(100vw-1rem))] overflow-y-auto rounded-xl p-0 shadow-lg dark:shadow-none"
        onEscapeKeyDown={(e) => { if (locked) e.preventDefault() }}
        onInteractOutside={(e) => { if (locked) e.preventDefault() }}
        onCloseAutoFocus={(e) => {
          e.preventDefault()
          if (anchor.isConnected) anchor.focus()
        }}
      >
        <div className="flex items-center justify-end gap-0.5 px-2 pt-2">
          {actions}
          <CalendarEventAction label={closeLabel} disabled={locked} onClick={() => onOpenChange(false)}>
            <X aria-hidden="true" className="size-5" />
          </CalendarEventAction>
        </div>

        <div className="space-y-4 px-5 pb-5 pt-1">
          <div className="grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-4">
            <span aria-hidden="true" className={cn("mt-1.5 size-4 rounded-sm", swatchClassName)} />
            <div className="min-w-0 space-y-1">
              <h3 id={titleId} className="break-words text-xl font-semibold leading-snug text-foreground">{title}</h3>
              <p className="text-sm text-foreground">{when}</p>
              {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
            </div>
          </div>
          {children}
        </div>
      </PopoverContent>
    </Popover>
  )
}
