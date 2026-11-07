"use client"

import { useParams } from "next/navigation"

import { SettingsPageView } from "@/components/config/SettingsShell"

export default function ConfiguracionSeccionPage() {
  const { seccion } = useParams<{ seccion: string }>()
  return <SettingsPageView slug={seccion} />
}
