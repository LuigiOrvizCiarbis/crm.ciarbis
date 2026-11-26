import type { Metadata } from "next"
import { BrandPilot } from "@/components/brand/BrandPilot"
import { BroadcastsDashboard } from "@/components/broadcasts/BroadcastsDashboard"

export const metadata: Metadata = {
  title: "Difusiones",
  description: "Planificá y medí campañas salientes por WhatsApp.",
}

export default function BroadcastsPage() {
  return (
    <>
      <BrandPilot />
      <BroadcastsDashboard />
    </>
  )
}
