import Link from "next/link"
import type { Metadata } from "next"
import { Button } from "@/components/ui/button"
import { InboxMockup } from "@/components/landing/InboxMockup"
import { MessageSquare, Users, KanbanSquare, CalendarDays } from "lucide-react"

export const metadata: Metadata = {
  title: "Social Impulse CRM — CRM conversacional con IA para WhatsApp e Instagram",
  description:
    "Social Impulse CRM centraliza las conversaciones de WhatsApp, Instagram y Facebook en una bandeja única, con gestión de contactos, pipeline de ventas y agendamiento de reuniones en Google Calendar.",
  alternates: {
    canonical: "https://sicrmapp.com",
  },
}

const features = [
  {
    icon: MessageSquare,
    title: "Bandeja omnicanal",
    description:
      "Recibí y respondé mensajes de WhatsApp, Instagram y Facebook desde una sola bandeja compartida por todo el equipo, en tiempo real.",
  },
  {
    icon: Users,
    title: "Gestión de contactos",
    description:
      "Cada conversación queda asociada a un contacto con su historial completo, campos personalizables e importación desde CSV.",
  },
  {
    icon: KanbanSquare,
    title: "Pipeline de ventas",
    description:
      "Convertí conversaciones en oportunidades y seguí su avance por las etapas de tu embudo en un tablero Kanban.",
  },
]

const googleScopes = [
  {
    scope: "https://www.googleapis.com/auth/calendar.events.owned",
    usage:
      "Permitir crear, consultar, actualizar y cancelar eventos en los calendarios de Google de los que sos propietario. Social Impulse usa ese permiso únicamente para gestionar las reuniones que agendás desde el CRM; no importa ni analiza el resto de tus eventos.",
  },
  {
    scope: "openid · email",
    usage:
      "Identificar qué cuenta de Google quedó vinculada, para mostrarte cuál está conectada y asociar los eventos al vendedor correcto.",
  },
]

export default function HomePage() {
  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="border-b">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <span className="text-lg font-bold tracking-tight">Social Impulse CRM</span>
          <div className="flex items-center gap-2">
            <Button asChild variant="ghost" size="sm">
              <Link href="/login">Iniciar sesión</Link>
            </Button>
            <Button asChild size="sm">
              <Link href="/register">Crear cuenta</Link>
            </Button>
          </div>
        </div>
      </header>

      <main>
        {/* Hero: único momento de color saturado de la página. */}
        <section className="bg-primary text-primary-foreground">
          <div className="mx-auto grid max-w-6xl items-center gap-x-12 gap-y-12 px-6 py-[clamp(3.5rem,9vw,6.5rem)] lg:grid-cols-[1.05fr_0.95fr]">
            <div className="animate-landing-rise">
              <h1 className="text-pretty text-[clamp(2rem,4.4vw,3.25rem)] font-semibold leading-[1.08] tracking-[-0.02em]">
                Social Impulse CRM es una aplicación web que centraliza las conversaciones de WhatsApp, Instagram
                y Facebook de tu empresa en una bandeja única
              </h1>
              <p className="mt-6 max-w-[62ch] text-lg leading-relaxed text-primary-foreground/85">
                Los equipos de ventas y soporte la usan para responder los mensajes de sus clientes desde un solo
                lugar, registrarlos como contactos, seguir cada venta por un pipeline y agendar reuniones en el
                Google Calendar del vendedor sin salir de la conversación.
              </p>
              <div className="mt-9 flex flex-wrap gap-3">
                <Button
                  asChild
                  size="lg"
                  className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                >
                  <Link href="/register">Crear cuenta gratis</Link>
                </Button>
                <Button
                  asChild
                  size="lg"
                  variant="outline"
                  className="border-primary-foreground/35 bg-transparent text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                >
                  <Link href="/pricing">Ver planes</Link>
                </Button>
              </div>
            </div>

            <div className="animate-landing-rise [animation-delay:120ms]">
              <InboxMockup />
            </div>
          </div>
        </section>

        {/* Cumplimiento: posición y copy fijados por la verificación de Google. */}
        <section className="border-b">
          <div className="mx-auto max-w-6xl px-6 py-[clamp(3rem,6vw,4.5rem)]">
            <div className="grid gap-x-12 gap-y-8 lg:grid-cols-[0.9fr_1.1fr]">
              <div>
                <div className="flex items-center gap-2 text-primary">
                  <CalendarDays className="h-5 w-5" aria-hidden="true" />
                  <span className="text-sm font-medium">Integración con Google</span>
                </div>
                <h2 className="mt-3 text-pretty text-3xl font-semibold tracking-[-0.015em]">
                  Por qué Social Impulse CRM pide acceso a Google Calendar
                </h2>
                <p className="mt-4 max-w-[60ch] text-muted-foreground">
                  Cuando cerrás una reunión con un cliente por chat, la aplicación crea el evento en el Google
                  Calendar del vendedor que la agendó, así no tiene que cargarla a mano. Esa es la única función
                  que necesita tus datos de Google. La conexión es{" "}
                  <strong className="font-semibold text-foreground">opcional</strong>: el resto del CRM funciona
                  sin vincular ninguna cuenta de Google, y podés revocar el acceso cuando quieras desde la
                  configuración de la aplicación o desde los permisos de tu cuenta de Google.
                </p>
              </div>

              <dl className="space-y-5">
                {googleScopes.map((item) => (
                  <div key={item.scope} className="border-t pt-5">
                    <dt className="font-mono text-[0.8125rem] leading-snug break-all text-foreground">
                      {item.scope}
                    </dt>
                    <dd className="mt-2 max-w-[62ch] text-sm leading-relaxed text-muted-foreground">
                      {item.usage}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>
        </section>

        <section className="mx-auto max-w-6xl px-6 py-[clamp(3rem,6vw,4.5rem)]">
          <h2 className="text-3xl font-semibold tracking-[-0.015em]">Qué podés hacer con Social Impulse CRM</h2>
          <div className="mt-10 divide-y">
            {features.map((feature) => (
              <div
                key={feature.title}
                className="grid gap-x-8 gap-y-2 py-7 first:pt-0 sm:grid-cols-[minmax(0,15rem)_1fr]"
              >
                <h3 className="flex items-center gap-2.5 text-lg font-semibold">
                  <feature.icon className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                  {feature.title}
                </h3>
                <p className="max-w-[68ch] text-muted-foreground">{feature.description}</p>
              </div>
            ))}
          </div>
        </section>

        <section className="border-t">
          <div className="mx-auto max-w-6xl px-6 py-[clamp(2.5rem,5vw,4rem)]">
            <h2 className="text-xl font-semibold">Cómo usa Social Impulse CRM tus datos de Google</h2>
            <div className="mt-5 max-w-[72ch] space-y-4 text-sm leading-relaxed text-muted-foreground">
              <p>
                La aplicación solo accede a tu cuenta de Google si vos elegís vincularla desde la configuración,
                y únicamente para gestionar los eventos de las reuniones que agendás desde el CRM, según se
                detalla más arriba. No usamos esos datos para publicidad, no los vendemos y no los compartimos
                con terceros.
              </p>
              <p>
                El uso que Social Impulse CRM hace de la información recibida de las APIs de Google se ajusta a
                la{" "}
                <a
                  href="https://developers.google.com/terms/api-services-user-data-policy"
                  className="underline underline-offset-4 hover:text-foreground"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Política de Datos de Usuario de los Servicios de API de Google
                </a>
                , incluidos los requisitos de Uso Limitado.
              </p>
            </div>
          </div>
        </section>
      </main>

      <footer className="border-t">
        <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-8 text-sm text-muted-foreground sm:flex-row">
          <span>© {new Date().getFullYear()} Social Impulse. Todos los derechos reservados.</span>
          <nav className="flex gap-6">
            <Link href="/privacy-policy" className="hover:text-foreground">
              Política de privacidad
            </Link>
            <Link href="/terms" className="hover:text-foreground">
              Términos
            </Link>
            <Link href="/data-deletion" className="hover:text-foreground">
              Eliminación de datos
            </Link>
          </nav>
        </div>
      </footer>
    </div>
  )
}
