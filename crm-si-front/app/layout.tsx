import type React from "react"
import type { Metadata } from "next"
import "./globals.css"
import ClientLayout from "./client-layout"

export const metadata: Metadata = {
  metadataBase: new URL("https://sicrmapp.com"),
  // Plantilla: las pantallas de la app se ven como "BIMS" o "Difusiones | BIMS" en la pestaña.
  // Open Graph y la landing conservan "Social Impulse CRM" hasta renombrar la app en las
  // consolas de Google (OAuth) y Meta: el nombre público tiene que coincidir con el registrado.
  title: { default: "BIMS", template: "%s | BIMS" },
  description:
    "CRM conversacional con IA para WhatsApp, Instagram y más canales. Automatizá ventas y mejorá conversiones.",
  keywords: "CRM, WhatsApp Business, Instagram, IA, automatización, ventas, conversaciones",
  authors: [{ name: "Social Impulse" }],
  creator: "Social Impulse",
  publisher: "Social Impulse",
  // Isotipo de BIMS: SVG para navegadores actuales, .ico de respaldo (16/32/48) y PNG opaco para iOS.
  icons: {
    icon: [
      { url: "/brand/bims-mark.svg", type: "image/svg+xml" },
      { url: "/favicon.ico", sizes: "48x48" },
    ],
    apple: "/brand/apple-touch-icon.png",
  },
  openGraph: {
    title: "Social Impulse CRM | CRM omnicanal con IA",
    description: "CRM conversacional con IA para WhatsApp, Instagram y más canales",
    url: "https://sicrmapp.com",
    siteName: "Social Impulse CRM",
    images: [
      {
        url: "/og-image.png",
        width: 1200,
        height: 630,
        alt: "Social Impulse CRM",
      },
    ],
    locale: "es_ES",
    type: "website",
  },
  twitter: {
    card: "summary_large_image",
    title: "Social Impulse CRM | CRM omnicanal con IA",
    description: "CRM conversacional con IA para WhatsApp, Instagram y más canales",
    images: ["/og-image.png"],
  },
  robots: {
    index: true,
    follow: true,
    googleBot: {
      index: true,
      follow: true,
      "max-video-preview": -1,
      "max-image-preview": "large",
      "max-snippet": -1,
    },
  },
  generator: "v0.app",
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode
}>) {
  return <ClientLayout>{children}</ClientLayout>
}
