import { Bot, CalendarCheck, MessageCircle } from "lucide-react"
import { PlayOnView } from "@/components/landing/PlayOnView"
import { cn } from "@/lib/utils"

// Las mismas etapas que el tablero de PipelineMockup: es el mismo embudo.
const stages = ["Nuevo", "Calificado", "Reunión", "Ganada"]
const MEETING_STAGE = 2

/**
 * Escena del hero: una conversación real de punta a punta. Entra un lead por WhatsApp, la IA
 * responde y agenda, la reunión cae en Google Calendar y la oportunidad avanza de etapa.
 * Es decorativa (aria-hidden): el titular y la bajada cuentan lo mismo en texto.
 * Bajo prefers-reduced-motion se muestra directamente el estado final.
 */
export function LiveThread() {
  return (
    <PlayOnView className="select-none">
      <div aria-hidden="true" className="overflow-hidden rounded-2xl border bg-card shadow-2xl">
        <ThreadHeader />

        <div className="relative px-4 pb-5 pt-4 sm:px-5">
          <div className="landing-dots pointer-events-none absolute inset-0 text-muted-foreground opacity-20" />

          <div className="relative flex flex-col gap-2">
            <span className="mx-auto mb-1 rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
              Hoy
            </span>

            <Bubble side="in" time="19:42" delay="[animation-delay:300ms]">
              Hola! Vi el anuncio. ¿Hacen envíos a Córdoba? Necesito 40 unidades para el local.
            </Bubble>

            <Bubble side="ai" time="19:42" delay="[animation-delay:1100ms]">
              ¡Hola Martina! Sí, enviamos a todo el país y para 40 unidades te corresponde precio
              mayorista. ¿Te llamamos mañana para cerrarlo? Tengo 10:00 o 16:30.
            </Bubble>

            <Bubble side="in" time="19:44" delay="[animation-delay:2000ms]">
              Mañana a las 10 me viene bien
            </Bubble>

            <div className="animate-landing-in mx-auto mt-1 flex items-center gap-1.5 rounded-full border border-success/30 bg-success/10 px-3 py-1 text-xs font-medium text-success [animation-delay:2700ms]">
              <CalendarCheck className="size-3.5 shrink-0" />
              Reunión agendada en Google Calendar, mañana 10:00
            </div>
          </div>
        </div>

        <DealStrip />
      </div>
    </PlayOnView>
  )
}

function ThreadHeader() {
  return (
    <div className="flex items-center gap-3 border-b px-4 py-3 sm:px-5">
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
        MR
        <span className="absolute -bottom-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-card">
          <MessageCircle className="size-2.5 text-green-400" />
        </span>
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold">Martina Ruiz</p>
        <p className="truncate text-xs text-muted-foreground">WhatsApp · Mayorista Norte</p>
      </div>
      <span className="flex shrink-0 items-center gap-1 rounded-full border border-ai/30 bg-ai/10 px-2 py-0.5 text-[11px] font-medium text-ai">
        <Bot className="size-3" />
        IA activa
      </span>
    </div>
  )
}

interface BubbleProps {
  side: "in" | "ai"
  time: string
  delay: string
  children: React.ReactNode
}

function Bubble({ side, time, delay, children }: BubbleProps) {
  const isAi = side === "ai"
  return (
    <div
      className={cn(
        "animate-landing-in max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-snug",
        isAi
          ? "self-end rounded-br-sm border border-ai/30 bg-ai/10 text-foreground"
          : "self-start rounded-bl-sm bg-muted text-foreground",
        delay
      )}
    >
      {isAi && (
        <span className="mb-1 flex items-center gap-1 text-[11px] font-medium text-ai">
          <Bot className="size-3" />
          Respondió la IA
        </span>
      )}
      <p className="text-pretty">{children}</p>
      <span className="mt-1 block text-right text-[11px] tabular-nums text-muted-foreground">{time}</span>
    </div>
  )
}

function DealStrip() {
  return (
    <div className="border-t px-4 pb-4 pt-3 sm:px-5">
      <div className="flex items-baseline justify-between gap-3 text-xs">
        <span className="font-medium text-muted-foreground">Embudo de ventas</span>
        <span className="font-semibold tabular-nums">$ 1.240.000</span>
      </div>

      <div className="mt-3 grid grid-cols-4 text-[11px]">
        {stages.map((stage, index) => (
          <span
            key={stage}
            className={cn(
              "truncate px-1 text-center",
              index === MEETING_STAGE ? "font-semibold text-foreground" : "text-muted-foreground"
            )}
          >
            {stage}
          </span>
        ))}
      </div>

      <div className="relative mt-2 grid grid-cols-4 rounded-full bg-muted">
        <span className="animate-landing-advance col-start-3 flex h-7 items-center justify-center gap-1.5 rounded-full bg-primary px-2 text-xs font-semibold text-primary-foreground [animation-delay:3300ms]">
          <span className="truncate">Martina</span>
        </span>
      </div>
    </div>
  )
}
