"use client"

import { PlatformIcon } from "./PlatformIcon"
import { FilterType } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

interface ChatFiltersProps {
  activeFilter: FilterType
  onFilterChange: (filter: FilterType) => void
  availableChannelTypes?: FilterType[]
}

/** Nombres de canal para filtros y títulos; son nombres propios, no se traducen. */
export const CHANNEL_FILTERS: { key: Exclude<FilterType, "todos">; label: string }[] = [
  { key: "whatsapp", label: "WhatsApp" },
  { key: "instagram", label: "Instagram" },
  { key: "facebook", label: "Facebook" },
  { key: "linkedin", label: "LinkedIn" },
  { key: "telegram", label: "Telegram" },
  { key: "web", label: "Web" },
  { key: "mail", label: "Email" },
  { key: "manual", label: "Manual" },
]

/** Filtro por canal. "No leídos" no va acá: es una de las vistas de la bandeja. */
export const ChatFilters = ({
  activeFilter,
  onFilterChange,
  availableChannelTypes,
}: ChatFiltersProps) => {
  const { t } = useTranslation()

  const buttons = [
    { key: "todos" as FilterType, label: t("chats.all"), icon: null },
    ...CHANNEL_FILTERS
      .filter((item) => !availableChannelTypes || availableChannelTypes.includes(item.key))
      .map((item) => ({ ...item, icon: <PlatformIcon type={item.key} className="size-3.5" aria-hidden="true" /> })),
  ]

  return (
    <div role="group" aria-label={t("chats.channelFilterLabel")} className="flex flex-wrap gap-1 border-b border-border bg-card px-4 py-3">
      {buttons.map((button) => {
        const active = activeFilter === button.key
        return (
          <button
            key={button.key}
            type="button"
            aria-pressed={active}
            className={`inline-flex h-8 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-2 text-xs font-medium outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 ${
              active
                ? "border-primary/30 bg-primary/10 text-primary"
                : "border-border text-muted-foreground hover:bg-accent hover:text-foreground"
            }`}
            onClick={() => onFilterChange(button.key)}
          >
            {button.icon}
            {button.label}
          </button>
        )
      })}
    </div>
  )
}
