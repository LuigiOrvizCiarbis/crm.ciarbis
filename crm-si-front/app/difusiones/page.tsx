import type { Metadata } from "next"
import { BroadcastsDashboard } from "@/components/broadcasts/BroadcastsDashboard"

export const metadata: Metadata = {
  title: "Difusiones | Social Impulse CRM",
  description: "Planificá y medí campañas salientes por WhatsApp.",
}

export default function BroadcastsPage() {
  return <BroadcastsDashboard />
}
