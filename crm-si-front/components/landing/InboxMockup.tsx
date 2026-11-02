import { Instagram, Facebook, MessageSquare } from "lucide-react"

type MockConversation = {
  name: string
  message: string
  time: string
  unread: number
  channel: "whatsapp" | "instagram" | "facebook"
  active?: boolean
}

/**
 * Réplica estática de la bandeja real (ver components/chat/ConversationList.tsx).
 * Es decorativa: el texto del hero comunica lo mismo para lectores de pantalla.
 */
const conversations: MockConversation[] = [
  {
    name: "María González",
    message: "Hola, me interesa conocer más sobre sus servicios",
    time: "10:30",
    unread: 2,
    channel: "instagram",
    active: true,
  },
  {
    name: "Carlos Rodríguez",
    message: "¿Podrían enviarme una cotización?",
    time: "09:45",
    unread: 1,
    channel: "whatsapp",
  },
  {
    name: "Ana Martínez",
    message: "Perfecto, agendemos una reunión la próxima semana",
    time: "Ayer",
    unread: 0,
    channel: "facebook",
  },
  {
    name: "Laura Pérez",
    message: "¿Cuándo podríamos hacer una videollamada?",
    time: "2d",
    unread: 3,
    channel: "whatsapp",
  },
]

const channelIcon = {
  whatsapp: { Icon: MessageSquare, className: "text-green-500" },
  instagram: { Icon: Instagram, className: "text-pink-500" },
  facebook: { Icon: Facebook, className: "text-blue-500" },
}

function initials(name: string) {
  return name
    .split(" ")
    .map((part) => part[0])
    .join("")
}

export function InboxMockup() {
  return (
    <div
      aria-hidden="true"
      className="overflow-hidden rounded-xl border border-border bg-card shadow-lg select-none"
    >
      <div className="border-b border-border px-4 py-3">
        <p className="text-sm font-semibold text-card-foreground">Conversaciones</p>
        <p className="mt-0.5 text-xs text-muted-foreground">Todos los canales • 6 sin leer</p>
      </div>

      <div className="divide-y divide-border">
        {conversations.map((conversation) => {
          const { Icon, className } = channelIcon[conversation.channel]

          return (
            <div
              key={conversation.name}
              className={`flex items-start gap-3 px-4 py-3 ${conversation.active ? "bg-muted" : ""}`}
            >
              <div className="relative shrink-0">
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-[0.6875rem] font-medium text-muted-foreground">
                  {initials(conversation.name)}
                </span>
                <span className="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-card">
                  <Icon className={`h-2.5 w-2.5 ${className}`} />
                </span>
              </div>

              <div className="min-w-0 flex-1">
                <div className="flex items-baseline justify-between gap-2">
                  <p className="truncate text-sm font-medium text-card-foreground">{conversation.name}</p>
                  <span className="shrink-0 text-[0.6875rem] text-muted-foreground">{conversation.time}</span>
                </div>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">{conversation.message}</p>
              </div>

              {conversation.unread > 0 && (
                <span className="mt-1 flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-primary px-1 text-[0.625rem] font-semibold text-primary-foreground">
                  {conversation.unread}
                </span>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
