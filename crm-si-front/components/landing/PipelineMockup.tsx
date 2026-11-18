import { CalendarCheck, Facebook, Instagram, MessageCircle } from "lucide-react"
import { PlayOnView } from "@/components/landing/PlayOnView"
import { cn } from "@/lib/utils"

type Deal = {
  name: string
  value: string
  channel: "whatsapp" | "instagram" | "facebook"
  stalledDays?: number
  /** La oportunidad del hilo del hero: entra al tablero cuando se ve y muestra su reunión. */
  meeting?: string
}

type Stage = {
  name: string
  total: string
  dotClass: string
  deals: Deal[]
  /** En el celular el tablero muestra sólo las etapas que cuentan la historia. */
  wideOnly?: boolean
}

/**
 * Tablero del embudo en miniatura (ver components/kanban-board.tsx). Los colores de etapa en la app
 * los elige cada equipo; acá van tokens para que el mock respete el tema. Decorativo.
 * Mismo elenco y mismas etapas que el hilo del hero, InboxMockup y HandoffMockup.
 */
const stages: Stage[] = [
  {
    name: "Nuevo",
    total: "$ 464.000",
    dotClass: "bg-info",
    wideOnly: true,
    deals: [
      { name: "Ana Martínez", value: "$ 84.000", channel: "instagram" },
      { name: "Lucía Gómez", value: "$ 380.000", channel: "instagram" },
    ],
  },
  {
    name: "Calificado",
    total: "$ 2.000.000",
    dotClass: "bg-warning",
    deals: [
      { name: "Carlos Rodríguez", value: "$ 1.150.000", channel: "whatsapp" },
      { name: "Laura Pérez", value: "$ 590.000", channel: "facebook" },
      { name: "Pablo Sosa", value: "$ 260.000", channel: "facebook", stalledDays: 8 },
    ],
  },
  {
    name: "Reunión",
    total: "$ 1.240.000",
    dotClass: "bg-primary",
    deals: [{ name: "Martina Ruiz", value: "$ 1.240.000", channel: "whatsapp", meeting: "Mañana 10:00" }],
  },
  {
    name: "Ganada",
    total: "$ 1.720.000",
    dotClass: "bg-success",
    wideOnly: true,
    deals: [{ name: "Diego Fernández", value: "$ 1.720.000", channel: "whatsapp" }],
  },
]

const channelIcon = {
  whatsapp: { Icon: MessageCircle, className: "text-green-600 dark:text-green-400" },
  instagram: { Icon: Instagram, className: "text-pink-600 dark:text-pink-400" },
  facebook: { Icon: Facebook, className: "text-blue-600 dark:text-blue-400" },
}

export function PipelineMockup() {
  return (
    <PlayOnView className="select-none">
      <div aria-hidden="true" className="overflow-hidden rounded-2xl border bg-card shadow-lg">
        <div className="flex items-baseline justify-between gap-3 border-b px-4 py-3">
          <span className="text-sm font-semibold">Embudo de ventas</span>
          <span className="text-xs font-medium tabular-nums text-muted-foreground">$ 5.424.000 en juego</span>
        </div>

        <div className="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
          {stages.map((stage) => (
            <div key={stage.name} className={cn("rounded-xl bg-muted/60 p-3", stage.wideOnly && "hidden sm:block")}>
              <div className="flex items-center gap-2 px-1">
                <span className={cn("size-2.5 shrink-0 rounded-full", stage.dotClass)} />
                <span className="text-sm font-semibold">{stage.name}</span>
                <span className="ml-auto text-xs font-medium tabular-nums text-muted-foreground">{stage.total}</span>
              </div>

              <div className="mt-3 flex flex-col gap-2">
                {stage.deals.map((deal) => (
                  <DealCard key={deal.name} deal={deal} />
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </PlayOnView>
  )
}

function DealCard({ deal }: { deal: Deal }) {
  const { Icon, className } = channelIcon[deal.channel]
  return (
    <div
      className={cn(
        "rounded-lg border bg-card px-3 py-2.5 shadow-xs",
        deal.meeting && "animate-landing-in border-primary/50 ring-1 ring-primary/30 [animation-delay:250ms]"
      )}
    >
      <div className="flex items-center gap-2">
        <Icon className={cn("size-3.5 shrink-0", className)} />
        <p className="truncate text-sm font-medium">{deal.name}</p>
      </div>
      <div className="mt-1 flex items-baseline justify-between gap-2">
        <span className="text-sm font-bold tabular-nums">{deal.value}</span>
        {deal.stalledDays && (
          <span className="text-[11px] font-medium text-warning">{deal.stalledDays} días sin moverse</span>
        )}
      </div>
      {deal.meeting && (
        <span className="animate-landing-in mt-2 flex w-fit items-center gap-1 rounded-full border border-success/30 bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success [animation-delay:900ms]">
          <CalendarCheck className="size-3 shrink-0" />
          {deal.meeting}
        </span>
      )}
    </div>
  )
}
