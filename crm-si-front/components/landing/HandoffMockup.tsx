import { Bot, CalendarClock, UserRound } from "lucide-react"

/**
 * Lo que ve el vendedor cuando la IA necesita a una persona: el aviso de traspaso con el resumen
 * (ver HumanHandoffBanner en el chat) y una reunión que la IA propuso y espera aprobación.
 * Decorativa; la sección lo cuenta en texto.
 */
export function HandoffMockup() {
  return (
    <div aria-hidden="true" className="select-none overflow-hidden rounded-2xl border bg-card shadow-lg">
      <div className="flex items-center gap-3 border-b px-4 py-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
          CR
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold">Carlos Rodríguez</p>
          <p className="truncate text-xs text-muted-foreground">WhatsApp · Distribuidora Sur</p>
        </div>
      </div>

      <div className="border-b px-4 py-3">
        <div className="rounded-lg border border-warning/30 bg-warning/10 p-3">
          <p className="flex items-center gap-1.5 text-sm font-semibold text-warning">
            <UserRound className="size-4 shrink-0" />
            La IA te pasa esta conversación
          </p>
          <p className="mt-1 text-pretty text-sm text-foreground">
            Pide precio por 200 unidades con factura A. Ya lo calificó como mayorista.
          </p>
          <span className="mt-3 inline-flex h-8 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground">
            Tomar
          </span>
        </div>
      </div>

      <div className="flex flex-col gap-2 px-4 py-4">
        <div className="max-w-[85%] self-start rounded-2xl rounded-bl-sm bg-muted px-3 py-2 text-sm">
          ¿Cuánto me sale si llevo 200 y necesito factura A?
        </div>
        <div className="max-w-[85%] self-end rounded-2xl rounded-br-sm border border-ai/30 bg-ai/10 px-3 py-2 text-sm">
          <span className="mb-1 flex items-center gap-1 text-[11px] font-medium text-ai">
            <Bot className="size-3" />
            Respondió la IA
          </span>
          Para ese volumen te escribe alguien del equipo en unos minutos.
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3 border-t px-4 py-3">
        <CalendarClock className="size-4 shrink-0 text-ai" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium">La IA propuso una reunión</p>
          <p className="text-xs text-muted-foreground">Llamada con Laura Pérez, jueves 16:30</p>
        </div>
        <span className="inline-flex h-8 items-center rounded-md border px-3 text-sm font-medium">Aprobar</span>
      </div>
    </div>
  )
}
