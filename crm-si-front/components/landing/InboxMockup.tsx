import { Facebook, Instagram, MessageCircle } from "lucide-react"
import { cn } from "@/lib/utils"

type MockConversation = {
  name: string
  message: string
  time: string
  unread: number
  channel: "whatsapp" | "instagram" | "facebook"
  assignee: string | null
  tag?: string
}

/**
 * Réplica estática de la bandeja real (ver components/chat/ConversationList.tsx): no leído por
 * peso y contador, responsable visible en cada fila. Es decorativa; la sección lo cuenta en texto.
 * Mismo elenco que el hilo del hero, HandoffMockup y PipelineMockup: la página sigue una sola tarde.
 */
const conversations: MockConversation[] = [
  {
    name: "Carlos Rodríguez",
    message: "¿Cuánto me sale si llevo 200 y necesito factura A?",
    time: "19:58",
    unread: 2,
    channel: "whatsapp",
    assignee: null,
    tag: "Mayorista",
  },
  {
    name: "Martina Ruiz",
    message: "Mañana a las 10 me viene bien",
    time: "19:44",
    unread: 0,
    channel: "whatsapp",
    assignee: "Sofía",
    tag: "Reunión",
  },
  {
    name: "Ana Martínez",
    message: "Vi el reel, ¿tienen talle M en negro?",
    time: "19:12",
    unread: 1,
    channel: "instagram",
    assignee: "Nico",
  },
  {
    name: "Diego Fernández",
    message: "Gracias! Ya hice la transferencia",
    time: "Ayer",
    unread: 0,
    channel: "whatsapp",
    assignee: "Sofía",
    tag: "Pagó",
  },
]

const channelIcon = {
  whatsapp: { Icon: MessageCircle, className: "text-green-600 dark:text-green-400", label: "WhatsApp" },
  instagram: { Icon: Instagram, className: "text-pink-600 dark:text-pink-400", label: "Instagram" },
  facebook: { Icon: Facebook, className: "text-blue-600 dark:text-blue-400", label: "Facebook" },
}

const filters = ["Todos", "Sin asignar", "Míos"]

function initials(name: string) {
  return name
    .split(" ")
    .map((part) => part[0])
    .join("")
}

export function InboxMockup() {
  return (
    <div aria-hidden="true" className="select-none overflow-hidden rounded-2xl border bg-card shadow-lg">
      <div className="flex items-center gap-1.5 border-b px-4 py-3">
        {filters.map((filter, index) => (
          <span
            key={filter}
            className={cn(
              "rounded-full px-2.5 py-1 text-xs font-medium",
              index === 0 ? "bg-foreground text-background" : "text-muted-foreground"
            )}
          >
            {filter}
          </span>
        ))}
      </div>

      <div className="divide-y">
        {conversations.map((conversation) => (
          <ConversationRow key={conversation.name} conversation={conversation} />
        ))}
      </div>
    </div>
  )
}

function ConversationRow({ conversation }: { conversation: MockConversation }) {
  const { Icon, className } = channelIcon[conversation.channel]
  const isUnread = conversation.unread > 0

  return (
    <div className="flex items-start gap-3 px-4 py-3">
      <span className="relative flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
        {initials(conversation.name)}
        <span className="absolute -bottom-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-card">
          <Icon className={cn("size-2.5", className)} />
        </span>
      </span>

      <div className="min-w-0 flex-1">
        <div className="flex items-baseline justify-between gap-2">
          <p className={cn("truncate text-sm", isUnread ? "font-semibold" : "font-medium")}>{conversation.name}</p>
          <span
            className={cn(
              "shrink-0 text-[11px] tabular-nums",
              isUnread ? "font-semibold text-primary" : "text-muted-foreground"
            )}
          >
            {conversation.time}
          </span>
        </div>
        <div className="mt-0.5 flex items-center gap-2">
          <p className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{conversation.message}</p>
          {isUnread && (
            <span className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold tabular-nums text-primary-foreground">
              {conversation.unread}
            </span>
          )}
        </div>
        <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] font-medium">
          {conversation.assignee ? (
            <span className="rounded-full bg-muted px-2 py-0.5 text-foreground">{conversation.assignee}</span>
          ) : (
            <span className="rounded-full border border-warning/30 bg-warning/10 px-2 py-0.5 text-warning">
              Sin asignar
            </span>
          )}
          {conversation.tag && (
            <span className="rounded-full border px-2 py-0.5 text-muted-foreground">{conversation.tag}</span>
          )}
        </div>
      </div>
    </div>
  )
}
