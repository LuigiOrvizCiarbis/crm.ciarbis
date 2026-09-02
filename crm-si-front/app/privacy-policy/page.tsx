"use client"

import { useState } from "react"
import Link from "next/link"

const content = {
  en: {
    title: "Privacy Policy",
    lastUpdated: "Last updated: September 2, 2026",
    login: "Log in",
    sections: [
      {
        title: "1. Introduction",
        content: (
          <>
            <p>
              Social Impulse (&quot;we&quot;, &quot;our&quot;, or &quot;us&quot;) operates the Social Impulse CRM platform
              (the &quot;Service&quot;). This Privacy Policy explains how we collect, use, disclose, and
              safeguard your information when you use our Service, including our integration with
              third-party messaging platforms such as WhatsApp, Instagram, and Facebook, and with Google
              Calendar when you choose to connect that account.
            </p>
            <p className="mt-2">
              By using Social Impulse, you agree to the collection and use of information in
              accordance with this policy.
            </p>
          </>
        ),
      },
      {
        title: "2. Information We Collect",
        content: (
          <>
            <h3 className="text-lg font-medium mt-4 mb-2">2.1 Information You Provide</h3>
            <ul className="list-disc pl-6 space-y-1">
              <li>Account registration information (name, email address, password)</li>
              <li>Business information (company name, phone number, address)</li>
              <li>Contact data you import or create within the CRM (names, phone numbers, email addresses)</li>
              <li>Messages and conversations you send and receive through connected channels</li>
              <li>Payment and billing information</li>
            </ul>
            <h3 className="text-lg font-medium mt-4 mb-2">2.2 Information Collected Automatically</h3>
            <ul className="list-disc pl-6 space-y-1">
              <li>Device information (browser type, operating system, device identifiers)</li>
              <li>Log data (IP address, access times, pages viewed)</li>
              <li>Usage data (features used, interactions within the platform)</li>
              <li>Cookies and similar tracking technologies</li>
            </ul>
            <h3 className="text-lg font-medium mt-4 mb-2">2.3 Information from Third-Party Platforms</h3>
            <p>When you connect messaging channels (WhatsApp, Instagram, Facebook), we receive:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Messages sent to and from your business accounts</li>
              <li>Contact profiles and phone numbers of people who message your business</li>
              <li>Message delivery and read status</li>
              <li>Media files shared in conversations (images, documents, audio)</li>
            </ul>
            <p className="mt-3">When you connect Google Calendar, we receive only the data needed to manage CRM meetings:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>The email address of the connected Google account</li>
              <li>The Calendar event created or managed for a CRM meeting, including its Calendar and Google Meet links</li>
            </ul>
          </>
        ),
      },
      {
        title: "3. How We Use Your Information",
        content: (
          <>
            <p>We use the information we collect to:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Provide, maintain, and improve our CRM Service</li>
              <li>Enable multi-channel messaging (WhatsApp, Instagram, Facebook) on your behalf</li>
              <li>Manage your contacts, conversations, and sales pipeline</li>
              <li>Create, update, and cancel meeting events in the Google calendar of the responsible user you select</li>
              <li>Provide AI-powered features such as conversation analysis and automated responses</li>
              <li>Send you service-related notifications</li>
              <li>Monitor and analyze usage patterns to improve user experience</li>
              <li>Detect, prevent, and address technical issues and security threats</li>
              <li>Comply with legal obligations</li>
            </ul>
          </>
        ),
      },
      {
        title: "4. Data Sharing and Disclosure",
        content: (
          <>
            <p>We do not sell your personal information. We may share your data with:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li><strong>Third-party messaging platforms</strong> (Meta/WhatsApp, Instagram, Facebook) as necessary to deliver messages on your behalf</li>
              <li><strong>Google Calendar</strong> when you connect the integration, to create and manage meetings requested from the CRM</li>
              <li><strong>Service providers</strong> who assist us in operating our platform (hosting, analytics, payment processing)</li>
              <li><strong>Legal authorities</strong> when required by law, regulation, or legal process</li>
              <li><strong>Business transfers</strong> in connection with a merger, acquisition, or sale of assets</li>
            </ul>
          </>
        ),
      },
      {
        title: "5. Data Security",
        content: (
          <>
            <p>We implement appropriate technical and organizational measures to protect your data, including:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Encryption of data in transit (TLS/SSL) and at rest</li>
              <li>Encryption of third-party API credentials and access tokens</li>
              <li>Multi-tenant data isolation ensuring your data is only accessible to your organization</li>
              <li>Regular security assessments and monitoring</li>
              <li>Access controls and authentication via secure token-based systems</li>
            </ul>
          </>
        ),
      },
      {
        title: "6. Data Retention",
        content: (
          <>
            <p>
              We retain your data for as long as your account is active or as needed to provide you
              with our services. When you delete your account, we will delete or anonymize your
              personal data within 90 days, except where we are required to retain it for legal or
              regulatory purposes.
            </p>
            <p className="mt-2">
              Google OAuth credentials and connected account information are retained while the
              Google Calendar integration remains active. When you disconnect the integration, we
              revoke the available Google access token and delete the stored OAuth credentials and
              connection record. Event identifiers and Calendar or Google Meet links associated with
              previously synchronized CRM meetings may remain in the CRM task history until the task
              or account is deleted. Disconnecting the integration does not delete events already
              created in your Google Calendar.
            </p>
          </>
        ),
      },
      {
        title: "7. Your Rights",
        content: (
          <>
            <p>Depending on your location, you may have the following rights:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Access the personal data we hold about you</li>
              <li>Request correction of inaccurate data</li>
              <li>Request deletion of your data</li>
              <li>Object to or restrict processing of your data</li>
              <li>Data portability — receive your data in a structured format</li>
              <li>Withdraw consent at any time where processing is based on consent</li>
            </ul>
            <p className="mt-2">To exercise any of these rights, please contact us at the email address below.</p>
          </>
        ),
      },
      {
        title: "8. Third-Party Services",
        content: (
          <>
            <p>Our Service integrates with third-party platforms including Meta (WhatsApp, Instagram, Facebook) and Google Calendar. Your use of these platforms is subject to their respective privacy policies:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Meta Privacy Policy: <a href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener noreferrer" className="text-primary underline">https://www.facebook.com/privacy/policy/</a></li>
              <li>WhatsApp Privacy Policy: <a href="https://www.whatsapp.com/legal/privacy-policy" target="_blank" rel="noopener noreferrer" className="text-primary underline">https://www.whatsapp.com/legal/privacy-policy</a></li>
              <li>Google Privacy Policy: <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" className="text-primary underline">https://policies.google.com/privacy</a></li>
            </ul>
          </>
        ),
      },
      {
        title: "9. Google API Data and Artificial Intelligence Services",
        content: (
          <>
            <h3 className="text-lg font-medium mt-4 mb-2">9.1 Google Data We Process</h3>
            <p>
              When you connect Google Calendar, we process the connected Google account email
              address, the scopes granted, OAuth credentials required to maintain the connection,
              and data for CRM-managed meeting events. Event data may include the event identifier,
              title, description, start and end time, time zone, recurrence, attendees,
              synchronization status, Google Calendar link, and Google Meet link. We do not import
              complete calendars or access, analyze, or store unrelated Calendar events. We do not
              create aggregated or anonymized datasets from Google Calendar user data.
            </p>

            <h3 className="text-lg font-medium mt-4 mb-2">9.2 Purpose and Transfer</h3>
            <p>
              Google user data is used only to identify the connected account and to create,
              retrieve, update, and cancel meetings requested by the user through Social Impulse
              CRM. We do not sell Google user data or share it with advertisers, data brokers, or
              other third parties for advertising, profiling, lending, or unrelated commercial
              purposes. Our infrastructure providers may process this data only as necessary to
              operate and secure the Service and under applicable confidentiality and data
              protection obligations.
            </p>

            <h3 className="text-lg font-medium mt-4 mb-2">9.3 AI/ML Data Isolation</h3>
            <p>
              Social Impulse CRM offers separate artificial intelligence features for CRM
              conversations and other content provided directly through the CRM. Raw, aggregated,
              anonymized, or derived data obtained from Google Workspace APIs—including connected
              account information, OAuth credentials, event data, synchronization metadata, and
              Google Calendar or Google Meet links—is not transmitted to OpenAI, Anthropic, or any
              other AI/ML service. It is not used to develop, train, fine-tune, or improve
              generalized or personalized AI/ML models and is not transferred to third parties for
              those purposes.
            </p>

            <h3 className="text-lg font-medium mt-4 mb-2">9.4 Google Limited Use Compliance</h3>
            <p>
              The use and transfer to any other application of information received from Google APIs
              will adhere to the{" "}
              <a
                href="https://developers.google.com/terms/api-services-user-data-policy"
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary underline"
              >
                Google API Services User Data Policy
              </a>
              , including the Limited Use requirements.
            </p>
          </>
        ),
      },
      {
        title: "10. Cookies",
        content: (
          <p>
            We use cookies and similar technologies to maintain your session, remember your
            preferences, and analyze how our Service is used. You can control cookies through
            your browser settings, but disabling them may affect functionality.
          </p>
        ),
      },
      {
        title: "11. Children's Privacy",
        content: (
          <p>
            Our Service is not directed to individuals under the age of 18. We do not knowingly
            collect personal information from children. If we become aware that we have collected
            data from a child, we will take steps to delete it promptly.
          </p>
        ),
      },
      {
        title: "12. Changes to This Policy",
        content: (
          <p>
            We may update this Privacy Policy from time to time. We will notify you of any
            significant changes by posting the new policy on this page and updating the &quot;Last
            updated&quot; date. We encourage you to review this page periodically.
          </p>
        ),
      },
      {
        title: "13. Contact Us",
        content: (
          <>
            <p>If you have any questions about this Privacy Policy or our data practices, please contact us at:</p>
            <ul className="list-none pl-0 space-y-1 mt-2">
              <li><strong>Email:</strong> privacy@socialimpulse.com</li>
              <li><strong>Company:</strong> Social Impulse</li>
            </ul>
          </>
        ),
      },
    ],
  },
  es: {
    title: "Política de Privacidad",
    lastUpdated: "Última actualización: 2 de septiembre de 2026",
    login: "Iniciar sesión",
    sections: [
      {
        title: "1. Introducción",
        content: (
          <>
            <p>
              Social Impulse (&quot;nosotros&quot;, &quot;nuestro&quot; o &quot;nos&quot;) opera la plataforma Social Impulse CRM
              (el &quot;Servicio&quot;). Esta Política de Privacidad explica cómo recopilamos, usamos, divulgamos
              y protegemos su información cuando utiliza nuestro Servicio, incluyendo nuestra integración
              con plataformas de mensajería de terceros como WhatsApp, Instagram y Facebook, y con Google
              Calendar cuando usted decide conectar esa cuenta.
            </p>
            <p className="mt-2">
              Al utilizar Social Impulse, usted acepta la recopilación y el uso de información de
              acuerdo con esta política.
            </p>
          </>
        ),
      },
      {
        title: "2. Información que Recopilamos",
        content: (
          <>
            <h3 className="text-lg font-medium mt-4 mb-2">2.1 Información que Usted Proporciona</h3>
            <ul className="list-disc pl-6 space-y-1">
              <li>Información de registro de cuenta (nombre, correo electrónico, contraseña)</li>
              <li>Información comercial (nombre de empresa, teléfono, dirección)</li>
              <li>Datos de contactos que importa o crea en el CRM (nombres, teléfonos, correos electrónicos)</li>
              <li>Mensajes y conversaciones que envía y recibe a través de los canales conectados</li>
              <li>Información de pago y facturación</li>
            </ul>
            <h3 className="text-lg font-medium mt-4 mb-2">2.2 Información Recopilada Automáticamente</h3>
            <ul className="list-disc pl-6 space-y-1">
              <li>Información del dispositivo (tipo de navegador, sistema operativo, identificadores)</li>
              <li>Datos de registro (dirección IP, horarios de acceso, páginas visitadas)</li>
              <li>Datos de uso (funciones utilizadas, interacciones dentro de la plataforma)</li>
              <li>Cookies y tecnologías de seguimiento similares</li>
            </ul>
            <h3 className="text-lg font-medium mt-4 mb-2">2.3 Información de Plataformas de Terceros</h3>
            <p>Cuando conecta canales de mensajería (WhatsApp, Instagram, Facebook), recibimos:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Mensajes enviados y recibidos desde sus cuentas comerciales</li>
              <li>Perfiles de contacto y números de teléfono de personas que envían mensajes a su negocio</li>
              <li>Estado de entrega y lectura de mensajes</li>
              <li>Archivos multimedia compartidos en conversaciones (imágenes, documentos, audio)</li>
            </ul>
            <p className="mt-3">Cuando conecta Google Calendar, recibimos únicamente los datos necesarios para gestionar reuniones del CRM:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>La dirección de correo de la cuenta Google conectada</li>
              <li>El evento de Calendar creado o administrado por una reunión del CRM, incluido su enlace de Calendar y Google Meet</li>
            </ul>
          </>
        ),
      },
      {
        title: "3. Cómo Usamos su Información",
        content: (
          <>
            <p>Usamos la información que recopilamos para:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Proporcionar, mantener y mejorar nuestro Servicio CRM</li>
              <li>Habilitar mensajería multicanal (WhatsApp, Instagram, Facebook) en su nombre</li>
              <li>Gestionar sus contactos, conversaciones y pipeline de ventas</li>
              <li>Crear, actualizar y cancelar eventos de reuniones en el calendario de Google del responsable que usted elija</li>
              <li>Proporcionar funciones impulsadas por IA como análisis de conversaciones y respuestas automatizadas</li>
              <li>Enviarle notificaciones relacionadas con el servicio</li>
              <li>Monitorear y analizar patrones de uso para mejorar la experiencia</li>
              <li>Detectar, prevenir y abordar problemas técnicos y amenazas de seguridad</li>
              <li>Cumplir con obligaciones legales</li>
            </ul>
          </>
        ),
      },
      {
        title: "4. Compartición y Divulgación de Datos",
        content: (
          <>
            <p>No vendemos su información personal. Podemos compartir sus datos con:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li><strong>Plataformas de mensajería de terceros</strong> (Meta/WhatsApp, Instagram, Facebook) según sea necesario para enviar mensajes en su nombre</li>
              <li><strong>Google Calendar</strong> cuando usted conecta la integración, para crear y administrar las reuniones solicitadas desde el CRM</li>
              <li><strong>Proveedores de servicios</strong> que nos asisten en la operación de nuestra plataforma (hosting, analítica, procesamiento de pagos)</li>
              <li><strong>Autoridades legales</strong> cuando lo requiera la ley, regulación o proceso legal</li>
              <li><strong>Transferencias comerciales</strong> en conexión con una fusión, adquisición o venta de activos</li>
            </ul>
          </>
        ),
      },
      {
        title: "5. Seguridad de Datos",
        content: (
          <>
            <p>Implementamos medidas técnicas y organizativas apropiadas para proteger sus datos, incluyendo:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Cifrado de datos en tránsito (TLS/SSL) y en reposo</li>
              <li>Cifrado de credenciales de API y tokens de acceso de terceros</li>
              <li>Aislamiento de datos multi-tenant que garantiza que sus datos solo sean accesibles para su organización</li>
              <li>Evaluaciones y monitoreo de seguridad regulares</li>
              <li>Controles de acceso y autenticación mediante sistemas seguros basados en tokens</li>
            </ul>
          </>
        ),
      },
      {
        title: "6. Retención de Datos",
        content: (
          <>
            <p>
              Retenemos sus datos mientras su cuenta esté activa o según sea necesario para proporcionarle
              nuestros servicios. Cuando elimine su cuenta, eliminaremos o anonimizaremos sus datos personales
              dentro de 90 días, excepto cuando estemos obligados a retenerlos por motivos legales o regulatorios.
            </p>
            <p className="mt-2">
              Las credenciales OAuth de Google y la información de la cuenta conectada se conservan mientras la
              integración con Google Calendar permanezca activa. Cuando desconecta la integración, revocamos el
              token de acceso de Google disponible y eliminamos las credenciales OAuth y el registro de conexión
              almacenados. Los identificadores de eventos y enlaces de Calendar o Google Meet asociados con
              reuniones del CRM previamente sincronizadas pueden permanecer en el historial de la tarea hasta que
              la tarea o la cuenta sean eliminadas. Desconectar la integración no elimina los eventos que ya fueron
              creados en su Google Calendar.
            </p>
          </>
        ),
      },
      {
        title: "7. Sus Derechos",
        content: (
          <>
            <p>Dependiendo de su ubicación, puede tener los siguientes derechos:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Acceder a los datos personales que tenemos sobre usted</li>
              <li>Solicitar la corrección de datos inexactos</li>
              <li>Solicitar la eliminación de sus datos</li>
              <li>Oponerse o restringir el procesamiento de sus datos</li>
              <li>Portabilidad de datos — recibir sus datos en un formato estructurado</li>
              <li>Retirar el consentimiento en cualquier momento cuando el procesamiento se base en el consentimiento</li>
            </ul>
            <p className="mt-2">Para ejercer cualquiera de estos derechos, contáctenos en la dirección de correo electrónico a continuación.</p>
          </>
        ),
      },
      {
        title: "8. Servicios de Terceros",
        content: (
          <>
            <p>Nuestro Servicio se integra con plataformas de terceros incluyendo Meta (WhatsApp, Instagram, Facebook) y Google Calendar. Su uso de estas plataformas está sujeto a sus respectivas políticas de privacidad:</p>
            <ul className="list-disc pl-6 space-y-1">
              <li>Política de Privacidad de Meta: <a href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener noreferrer" className="text-primary underline">https://www.facebook.com/privacy/policy/</a></li>
              <li>Política de Privacidad de WhatsApp: <a href="https://www.whatsapp.com/legal/privacy-policy" target="_blank" rel="noopener noreferrer" className="text-primary underline">https://www.whatsapp.com/legal/privacy-policy</a></li>
              <li>Política de Privacidad de Google: <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" className="text-primary underline">https://policies.google.com/privacy</a></li>
            </ul>
          </>
        ),
      },
      {
        title: "9. Datos de las APIs de Google y Servicios de Inteligencia Artificial",
        content: (
          <>
            <h3 className="text-lg font-medium mt-4 mb-2">9.1 Datos de Google que Procesamos</h3>
            <p>
              Cuando conecta Google Calendar, procesamos la dirección de correo de la cuenta Google conectada,
              los permisos concedidos, las credenciales OAuth necesarias para mantener la conexión y los datos de
              los eventos de reuniones gestionados por el CRM. Estos datos pueden incluir el identificador, título,
              descripción, fecha y hora de inicio y fin, zona horaria, recurrencia, invitados, estado de
              sincronización, enlace de Google Calendar y enlace de Google Meet. No importamos calendarios completos
              ni accedemos, analizamos o almacenamos eventos de Calendar ajenos a las reuniones gestionadas por el
              CRM. No generamos conjuntos de datos agregados o anonimizados a partir de los datos de usuarios de
              Google Calendar.
            </p>

            <h3 className="text-lg font-medium mt-4 mb-2">9.2 Finalidad y Transferencia</h3>
            <p>
              Los datos de usuarios de Google se utilizan únicamente para identificar la cuenta conectada y crear,
              consultar, actualizar y cancelar las reuniones solicitadas por el usuario mediante Social Impulse CRM.
              No vendemos datos de usuarios de Google ni los compartimos con anunciantes, corredores de datos u otros
              terceros para publicidad, elaboración de perfiles, préstamos o fines comerciales no relacionados.
              Nuestros proveedores de infraestructura pueden procesar estos datos únicamente en la medida necesaria
              para operar y proteger el Servicio y bajo las obligaciones aplicables de confidencialidad y protección
              de datos.
            </p>

            <h3 className="text-lg font-medium mt-4 mb-2">9.3 Aislamiento de Datos de IA/ML</h3>
            <p>
              Social Impulse CRM ofrece funciones independientes de inteligencia artificial para conversaciones del
              CRM y otros contenidos proporcionados directamente mediante el CRM. Los datos brutos, agregados,
              anonimizados o derivados obtenidos de las APIs de Google Workspace—incluyendo información de la cuenta
              conectada, credenciales OAuth, datos de eventos, metadatos de sincronización y enlaces de Google
              Calendar o Google Meet—no se transmiten a OpenAI, Anthropic ni a ningún otro servicio de IA/ML. Estos
              datos no se utilizan para desarrollar, entrenar, ajustar o mejorar modelos de IA/ML generales o
              personalizados, ni se transfieren a terceros para esos fines.
            </p>

            <h3 className="text-lg font-medium mt-4 mb-2">9.4 Cumplimiento de Uso Limitado de Google</h3>
            <p>
              El uso y la transferencia a cualquier otra aplicación de la información recibida de las APIs de Google
              cumplirán con la{" "}
              <a
                href="https://developers.google.com/terms/api-services-user-data-policy"
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary underline"
              >
                Política de Datos de Usuario de los Servicios de API de Google
              </a>
              , incluidos los requisitos de Uso Limitado (Limited Use).
            </p>
          </>
        ),
      },
      {
        title: "10. Cookies",
        content: (
          <p>
            Usamos cookies y tecnologías similares para mantener su sesión, recordar sus preferencias
            y analizar cómo se utiliza nuestro Servicio. Puede controlar las cookies a través de la
            configuración de su navegador, pero deshabilitarlas puede afectar la funcionalidad.
          </p>
        ),
      },
      {
        title: "11. Privacidad de Menores",
        content: (
          <p>
            Nuestro Servicio no está dirigido a personas menores de 18 años. No recopilamos
            intencionalmente información personal de menores. Si nos damos cuenta de que hemos
            recopilado datos de un menor, tomaremos medidas para eliminarlos de inmediato.
          </p>
        ),
      },
      {
        title: "12. Cambios a Esta Política",
        content: (
          <p>
            Podemos actualizar esta Política de Privacidad de vez en cuando. Le notificaremos de
            cualquier cambio significativo publicando la nueva política en esta página y actualizando
            la fecha de &quot;Última actualización&quot;. Le recomendamos revisar esta página periódicamente.
          </p>
        ),
      },
      {
        title: "13. Contáctenos",
        content: (
          <>
            <p>Si tiene alguna pregunta sobre esta Política de Privacidad o nuestras prácticas de datos, contáctenos en:</p>
            <ul className="list-none pl-0 space-y-1 mt-2">
              <li><strong>Email:</strong> privacy@socialimpulse.com</li>
              <li><strong>Empresa:</strong> Social Impulse</li>
            </ul>
          </>
        ),
      },
    ],
  },
}

export default function PrivacyPolicyPage() {
  const [lang, setLang] = useState<"en" | "es">("es")
  const t = content[lang]

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="border-b border-border">
        <div className="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
          <Link href="/" className="text-xl font-bold">
            Social Impulse
          </Link>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-1 rounded-lg border border-border p-0.5">
              <button
                onClick={() => setLang("es")}
                className={`px-2.5 py-1 text-xs font-medium rounded-md transition-colors ${lang === "es" ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}
              >
                ES
              </button>
              <button
                onClick={() => setLang("en")}
                className={`px-2.5 py-1 text-xs font-medium rounded-md transition-colors ${lang === "en" ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}
              >
                EN
              </button>
            </div>
            <Link
              href="/login"
              className="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
              {t.login}
            </Link>
          </div>
        </div>
      </header>

      <main className="max-w-4xl mx-auto px-6 py-12">
        <h1 className="text-3xl font-bold mb-2">{t.title}</h1>
        <p className="text-muted-foreground mb-8">{t.lastUpdated}</p>

        <div className="space-y-8">
          {t.sections.map((section, i) => (
            <section key={i}>
              <h2 className="text-xl font-semibold mb-3">{section.title}</h2>
              <div className="text-muted-foreground leading-relaxed">{section.content}</div>
            </section>
          ))}
        </div>
      </main>

      <footer className="border-t border-border mt-12">
        <div className="max-w-4xl mx-auto px-6 py-6 flex justify-center gap-6 text-sm text-muted-foreground">
          <Link href="/terms" className="hover:text-foreground transition-colors">
            {lang === "es" ? "Términos y Condiciones" : "Terms and Conditions"}
          </Link>
          <span>&copy; {new Date().getFullYear()} Social Impulse. All rights reserved.</span>
        </div>
      </footer>
    </div>
  )
}
