"use client"

import type { ReactNode } from "react"

import { useTranslation } from "@/hooks/useTranslation"

import { PhoneFrame } from "./PhoneFrame"

/** Teléfono con el chat de WhatsApp de la empresa: barra superior y fondo del chat. */
export function ChatScreen({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  return (
    <PhoneFrame>
      <div className="flex items-center gap-2 bg-[#008069] px-4 pb-3 pt-5 text-white">
        <span className="size-7 shrink-0 rounded-full bg-white/30" aria-hidden />
        <p className="min-w-0 truncate text-[15px] font-semibold">{t("flows.builder.message.chatTitle")}</p>
      </div>
      <div className="flex-1 overflow-y-auto bg-[#efeae2] p-3">{children}</div>
    </PhoneFrame>
  )
}
