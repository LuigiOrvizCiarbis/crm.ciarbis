import { Fragment } from "react"
import Link from "next/link"
import type { Metadata } from "next"
import { CalendarCheck, CalendarDays, Check, Facebook, Instagram, MessageCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { BimsLogo } from "@/components/brand/BimsLogo"
import { LiveThread } from "@/components/landing/LiveThread"
import { InboxMockup } from "@/components/landing/InboxMockup"
import { HandoffMockup } from "@/components/landing/HandoffMockup"
import { PipelineMockup } from "@/components/landing/PipelineMockup"
import { cn } from "@/lib/utils"

export const metadata: Metadata = {
  // Absoluto: la plantilla "%s | BIMS" duplicaría la marca.
  title: { absolute: "BIMS | CRM conversacional con IA para WhatsApp, Instagram y Facebook" },
  description:
    "BIMS junta las conversaciones de WhatsApp, Instagram y Facebook en una bandeja compartida, con IA que responde y agenda reuniones en Google Calendar y un embudo de ventas para seguir cada oportunidad.",
  alternates: {
    canonical: "https://sicrmapp.com",
  },
}

/*
 * La app figura en la pantalla de consentimiento de Google como "Social Impulse CRM": las dos
 * secciones de Google nombran la app así, con ese copy y en esa posición (la primera después del
 * hero), porque lo revisa la verificación de Google. El resto de la página es marca BIMS.
 */
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

const integrations = [
  { label: "WhatsApp", Icon: MessageCircle },
  { label: "Instagram", Icon: Instagram },
  { label: "Facebook", Icon: Facebook },
  { label: "Google Calendar", Icon: CalendarDays },
]

const inboxPoints = [
  "Respuestas en tiempo real, desde la compu o el celular.",
  "Contactos con historial, campos propios e importación desde CSV.",
  "Atajos para las respuestas que escribís todos los días.",
]

const aiPoints = [
  {
    title: "Responde al instante",
    description: "Contesta las preguntas de siempre a cualquier hora, con las instrucciones que le des.",
  },
  {
    title: "Agenda con tu aprobación",
    description: "Propone la reunión y la crea en Google Calendar directo o cuando la aprobás.",
  },
  {
    title: "Te pasa la conversación",
    description: "Cuando hace falta una persona, te avisa con un resumen de lo que pide el cliente.",
  },
]

/*
 * Cada respuesta sale de lo que hace el producto hoy: el plan Free no pide tarjeta ni vence solo
 * (el trial lo asigna un admin), sus límites son los de locales pricing.features y WhatsApp se
 * conecta con el Embedded Signup de Meta en modo coexistencia (hooks/useFacebookSDK.ts).
 */
const faqs = [
  {
    question: "¿Necesito tarjeta para empezar?",
    answer: "No. Creás la cuenta en el plan Free y pasás a un plan pago cuando lo necesites.",
  },
  {
    question: "¿Qué incluye el plan Free?",
    answer:
      "Un usuario, una cuenta por canal, la bandeja multicanal en tiempo real, contactos con importación CSV y el embudo de ventas. El chatbot con IA y el equipo con roles están en los planes pagos.",
  },
  {
    question: "¿Cómo se conecta WhatsApp?",
    answer:
      "Con la API oficial de WhatsApp Business de Meta. Vinculás el número desde la configuración, en la ventana de Meta, y si ya usás la app de WhatsApp Business podés seguir usándola en el celular.",
  },
  {
    question: "¿La IA puede prometer cualquier cosa?",
    answer:
      "Responde con las instrucciones que le das. Cuando una consulta necesita a alguien del equipo, te pasa la conversación con un resumen, y las reuniones las agenda directo o espera tu aprobación, como elijas.",
  },
]

// Número de ventas en formato internacional, sólo dígitos. Sin número configurado no hay link.
const salesWhatsApp = process.env.NEXT_PUBLIC_SALES_WHATSAPP?.replace(/\D/g, "") ?? ""
const salesWhatsAppHref = salesWhatsApp
  ? `https://wa.me/${salesWhatsApp}?text=${encodeURIComponent("Hola! Quiero saber más sobre BIMS.")}`
  : null

const SECTION_X = "mx-auto w-full max-w-6xl px-5 sm:px-8"

export default function HomePage() {
  return (
    <div className="brand-bims min-h-screen bg-background font-sans text-foreground">
      <SiteHeader />
      <Hero />

      <main>
        <GoogleCalendarAccess />
        <ProductTour />
        <BeforeSignUp />
        <ClosingCall />
        <GoogleDataUse />
      </main>

      <Footer />
    </div>
  )
}

function SiteHeader() {
  return (
    // Fijo: en el celular la página pasa varias pantallas sin otro botón de registro.
    <header className="dark landing-night sticky top-0 z-40 bg-background text-foreground">
      <div className={cn(SECTION_X, "flex items-center justify-between gap-4 py-3")}>
        <Link href="/" aria-label="BIMS, inicio" className="rounded-md">
          <BimsLogo />
        </Link>
        <nav aria-label="Principal" className="flex items-center gap-1 sm:gap-2">
          <Button asChild variant="ghost" className="h-10 px-3 sm:px-4">
            <Link href="/login">Iniciar sesión</Link>
          </Button>
          <Button asChild className="h-10 px-4">
            <Link href="/register">Crear cuenta</Link>
          </Button>
        </nav>
      </div>
    </header>
  )
}

function SignUpActions({ className }: { className?: string }) {
  return (
    <div className={className}>
      <div className="flex flex-wrap gap-3">
        <Button asChild size="lg" className="h-12 px-6 text-base">
          <Link href="/register">Crear cuenta</Link>
        </Button>
        <Button asChild size="lg" variant="outline" className="h-12 px-6 text-base">
          <Link href="/pricing">Ver planes</Link>
        </Button>
      </div>
      <p className="mt-4 text-sm text-muted-foreground">
        <span className="whitespace-nowrap">Plan Free sin tarjeta ·</span>{" "}
        <span className="whitespace-nowrap">WhatsApp por la API oficial de Meta</span>
      </p>
    </div>
  )
}

function SalesWhatsAppLink({ className }: { className?: string }) {
  if (!salesWhatsAppHref) return null
  return (
    <p className={cn("text-muted-foreground", className)}>
      ¿Preferís hablar con alguien?{" "}
      <a
        href={salesWhatsAppHref}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center gap-1.5 font-semibold text-foreground underline underline-offset-4 hover:text-primary"
      >
        <MessageCircle className="size-4 text-green-600 dark:text-green-400" aria-hidden="true" />
        Escribinos por WhatsApp
      </a>
    </p>
  )
}

function Hero() {
  return (
    <div className="dark landing-night bg-background text-foreground">
      <section
        aria-labelledby="hero-title"
        className={cn(
          SECTION_X,
          "grid items-center gap-x-16 gap-y-14 pb-[clamp(4rem,9vw,7rem)] pt-[clamp(2.5rem,7vw,5.5rem)] lg:grid-cols-[minmax(0,1fr)_minmax(0,29rem)]"
        )}
      >
        <div className="animate-landing-rise">
          <h1
            id="hero-title"
            className="text-balance text-[clamp(2.75rem,6.4vw,5.25rem)] font-extrabold leading-[0.98] tracking-[-0.035em]"
          >
            Ningún lead queda sin respuesta.
          </h1>
          <p className="mt-7 max-w-[46ch] text-pretty text-lg leading-relaxed text-muted-foreground">
            BIMS junta los chats de WhatsApp, Instagram y Facebook de tu equipo en una sola bandeja. La IA
            contesta lo de siempre, califica a cada lead y agenda la reunión en Google Calendar; tu equipo
            entra para cerrar la venta.
          </p>
          <SignUpActions className="mt-10" />
          <ul className="mt-10 flex flex-wrap gap-x-6 gap-y-3 text-sm text-muted-foreground">
            {integrations.map(({ label, Icon }) => (
              <li key={label} className="flex items-center gap-2">
                <Icon className="size-4 shrink-0" aria-hidden="true" />
                {label}
              </li>
            ))}
          </ul>
        </div>

        <LiveThread />
      </section>
    </div>
  )
}

/** Corta la URL del scope sólo después de cada barra, nunca a mitad de una palabra. */
function BreakableScope({ scope }: { scope: string }) {
  return scope.split("/").map((part, index) => (
    <Fragment key={index}>
      {index > 0 && (
        <>
          /<wbr />
        </>
      )}
      {part}
    </Fragment>
  ))
}

function GoogleCalendarAccess() {
  return (
    // Un aparte de confianza, no un capítulo: va más chico que las secciones de producto.
    <section aria-labelledby="google-access-title" className="border-b">
      <div className={cn(SECTION_X, "grid gap-x-16 gap-y-8 py-[clamp(2.5rem,4.5vw,3.75rem)] lg:grid-cols-[0.9fr_1.1fr]")}>
        <div>
          <p className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
            <CalendarCheck className="size-4 text-success" aria-hidden="true" />
            Integración con Google
          </p>
          <h2 id="google-access-title" className="mt-3 text-pretty text-2xl font-bold tracking-[-0.015em]">
            Por qué Social Impulse CRM pide acceso a Google Calendar
          </h2>
          <p className="mt-4 max-w-[60ch] text-[0.9375rem] leading-relaxed text-muted-foreground">
            Cuando cerrás una reunión con un cliente por chat, la aplicación crea el evento en el Google Calendar
            del vendedor que la agendó, así no tiene que cargarla a mano. Esa es la única función que necesita tus
            datos de Google. La conexión es <strong className="font-semibold text-foreground">opcional</strong>: el
            resto del CRM funciona sin vincular ninguna cuenta de Google, y podés revocar el acceso cuando quieras
            desde la configuración de la aplicación o desde los permisos de tu cuenta de Google.
          </p>
          <p className="mt-3 max-w-[60ch] text-sm leading-relaxed text-muted-foreground">
            En la pantalla de permisos de Google, BIMS figura como Social Impulse CRM, el nombre con el que Social
            Impulse registró la aplicación.
          </p>
        </div>

        <dl className="divide-y self-start border-y">
          {googleScopes.map((item) => (
            <div key={item.scope} className="py-5">
              <dt className="font-mono text-xs leading-snug text-foreground">
                <BreakableScope scope={item.scope} />
              </dt>
              <dd className="mt-2 max-w-[62ch] text-sm leading-relaxed text-muted-foreground">{item.usage}</dd>
            </div>
          ))}
        </dl>
      </div>
    </section>
  )
}

const FEATURE_TITLE = "text-pretty text-[clamp(1.5rem,2.6vw,2rem)] font-extrabold leading-tight tracking-[-0.02em]"

function ProductTour() {
  return (
    <section aria-labelledby="tour-title">
      <div className={cn(SECTION_X, "pt-[clamp(4rem,8vw,6.5rem)]")}>
        <h2
          id="tour-title"
          className="max-w-[18ch] text-balance text-[clamp(2.25rem,4.6vw,3.75rem)] font-extrabold leading-[1.02] tracking-[-0.03em]"
        >
          Del primer mensaje a la venta cerrada
        </h2>
        <p className="mt-5 max-w-[52ch] text-pretty text-lg text-muted-foreground">
          La conversación, el contacto y la oportunidad viven en el mismo lugar. Nadie copia datos de una app a otra.
        </p>
      </div>

      {/* Bandeja e IA van juntas (son la misma tarde); el embudo, que es el resultado, tiene más aire. */}
      <div
        className={cn(
          SECTION_X,
          "grid items-center gap-x-16 gap-y-10 pb-[clamp(2.5rem,5vw,4rem)] pt-[clamp(2.5rem,5vw,4rem)] lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)]"
        )}
      >
        <div>
          <h3 className={FEATURE_TITLE}>Una bandeja para todo el equipo</h3>
          <p className="mt-4 max-w-[52ch] leading-relaxed text-muted-foreground">
            Los mensajes de WhatsApp, Instagram y Facebook entran a la misma lista. Cada chat tiene responsable y queda
            ligado a su contacto con el historial completo, así nadie responde dos veces ni se pierde un lead entre
            turnos.
          </p>
          <ul className="mt-6 max-w-[52ch] space-y-2.5 text-sm">
            {inboxPoints.map((point) => (
              <li key={point} className="flex gap-2.5">
                <Check className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                {point}
              </li>
            ))}
          </ul>
        </div>
        <InboxMockup />
      </div>

      <div className="bg-muted/60">
        <div
          className={cn(
            SECTION_X,
            "grid items-center gap-x-16 gap-y-10 py-[clamp(3rem,6vw,5rem)] lg:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)]"
          )}
        >
          <div className="lg:order-2">
            <h3 className={FEATURE_TITLE}>La IA atiende la rutina y te avisa cuando te necesita</h3>
            <p className="mt-4 max-w-[52ch] leading-relaxed text-muted-foreground">
              Cada mensaje que manda la IA queda marcado, así siempre sabés qué dijo el bot y qué dijo tu equipo.
            </p>
            <dl className="mt-6 max-w-[52ch] divide-y border-y">
              {aiPoints.map((point) => (
                <div key={point.title} className="py-4">
                  <dt className="text-sm font-semibold">{point.title}</dt>
                  <dd className="mt-1 text-sm leading-relaxed text-muted-foreground">{point.description}</dd>
                </div>
              ))}
            </dl>
          </div>
          <div className="lg:order-1">
            <HandoffMockup />
          </div>
        </div>
      </div>

      <div className={cn(SECTION_X, "py-[clamp(4.5rem,9vw,7.5rem)]")}>
        <div className="grid gap-x-16 gap-y-4 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)] lg:items-end">
          <h3 className={FEATURE_TITLE}>Cada conversación, una oportunidad en el embudo</h3>
          <p className="max-w-[56ch] leading-relaxed text-muted-foreground">
            Vinculá el chat a una oportunidad y seguila etapa por etapa en un tablero. Ves cuánto hay en juego en cada
            etapa y qué ventas llevan días sin moverse.
          </p>
        </div>
        <div className="mt-10">
          <PipelineMockup />
        </div>
      </div>
    </section>
  )
}

function BeforeSignUp() {
  return (
    <section aria-labelledby="faq-title" className="border-t">
      <div
        className={cn(
          SECTION_X,
          "grid gap-x-16 gap-y-10 py-[clamp(3.5rem,7vw,5.5rem)] lg:grid-cols-[minmax(0,0.7fr)_minmax(0,1.3fr)]"
        )}
      >
        <div>
          <h2
            id="faq-title"
            className="text-pretty text-[clamp(1.75rem,3vw,2.25rem)] font-extrabold leading-tight tracking-[-0.025em]"
          >
            Antes de crear tu cuenta
          </h2>
          <SalesWhatsAppLink className="mt-4" />
        </div>
        <dl className="grid gap-x-12 gap-y-8 sm:grid-cols-2">
          {faqs.map((faq) => (
            <div key={faq.question}>
              <dt className="font-semibold">{faq.question}</dt>
              <dd className="mt-2 text-sm leading-relaxed text-muted-foreground">{faq.answer}</dd>
            </div>
          ))}
        </dl>
      </div>
    </section>
  )
}

function ClosingCall() {
  return (
    <section aria-labelledby="closing-title" className="dark landing-night bg-background text-foreground">
      <div className={cn(SECTION_X, "py-[clamp(4rem,8vw,6.5rem)]")}>
        <h2
          id="closing-title"
          className="max-w-[16ch] text-balance text-[clamp(2.25rem,4.6vw,3.75rem)] font-extrabold leading-[1.02] tracking-[-0.03em]"
        >
          Tu próximo cliente ya está escribiendo.
        </h2>
        <p className="mt-5 max-w-[44ch] text-pretty text-lg text-muted-foreground">
          Creá tu cuenta, conectá tus canales y empezá a responder desde BIMS.
        </p>
        <SignUpActions className="mt-9" />
        <SalesWhatsAppLink className="mt-8 text-sm" />
      </div>
    </section>
  )
}

function GoogleDataUse() {
  return (
    // Comparte fondo con el pie: el cierre queda como final y esto se lee como letra chica.
    <section aria-labelledby="google-data-title" className="bg-muted/50">
      <div className={cn(SECTION_X, "pb-10 pt-[clamp(2.5rem,5vw,3.5rem)]")}>
        <h2 id="google-data-title" className="text-lg font-bold tracking-[-0.01em]">
          Cómo usa Social Impulse CRM tus datos de Google
        </h2>
        <div className="mt-4 max-w-[72ch] space-y-4 text-sm leading-relaxed text-muted-foreground">
          <p>
            La aplicación solo accede a tu cuenta de Google si vos elegís vincularla desde la configuración, y
            únicamente para gestionar los eventos de las reuniones que agendás desde el CRM, según se detalla más
            arriba. No usamos esos datos para publicidad, no los vendemos y no los compartimos con terceros.
          </p>
          <p>
            El uso que Social Impulse CRM hace de la información recibida de las APIs de Google se ajusta a la{" "}
            <a
              href="https://developers.google.com/terms/api-services-user-data-policy"
              className="font-medium text-foreground underline underline-offset-4 hover:text-primary"
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
  )
}

const FOOTER_LINK = "inline-flex min-h-10 items-center hover:text-foreground"

function Footer() {
  return (
    <footer className="bg-muted/50">
      <div
        className={cn(
          SECTION_X,
          "flex flex-col gap-4 border-t py-6 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
        )}
      >
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-5">
          <BimsLogo />
          <span>© {new Date().getFullYear()} Social Impulse. Todos los derechos reservados.</span>
        </div>
        <nav aria-label="Legales" className="flex flex-wrap gap-x-6">
          <Link href="/privacy-policy" className={FOOTER_LINK}>
            Política de privacidad
          </Link>
          <Link href="/terms" className={FOOTER_LINK}>
            Términos
          </Link>
          <Link href="/data-deletion" className={FOOTER_LINK}>
            Eliminación de datos
          </Link>
        </nav>
      </div>
    </footer>
  )
}
