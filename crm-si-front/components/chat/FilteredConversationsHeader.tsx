"use client"

import { CHANNEL_FILTERS } from "@/components/chat/ChatFilters"
import type { ConversationView, FilterType } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

interface FilteredConversationsHeaderProps {
  view: ConversationView
  activeFilter: FilterType
}

/** Título de la lista: la vista elegida y, si hay, el canal ("Derivadas · WhatsApp"). */
export function FilteredConversationsHeader({ view, activeFilter }: FilteredConversationsHeaderProps) {
  const { t } = useTranslation()
  const channel = CHANNEL_FILTERS.find((item) => item.key === activeFilter)?.label

  return (
    <div className="border-b border-border bg-card px-4 py-3.5">
      <h2 className="text-sm font-semibold">
        {t(`chats.views.${view}`)}
        {channel && <span className="font-normal text-muted-foreground"> · {channel}</span>}
      </h2>
    </div>
  )
}
