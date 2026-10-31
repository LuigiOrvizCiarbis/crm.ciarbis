"use client"

import { Badge } from "@/components/Badges"
import { Button } from "@/components/ui/button"
import { useTranslation } from "@/hooks/useTranslation"

interface AISuggestionsProps {
  suggestions: string[]
  onSuggestionClick: (suggestion: string) => void
}

export function AISuggestions({ suggestions, onSuggestionClick }: AISuggestionsProps) {
  const { t } = useTranslation()

  if (suggestions.length === 0) return null

  return (
    <div className="border-b border-border bg-muted/30 px-4 py-2 sm:py-3">
      {/* El encabezado sólo aparece de sm en adelante: en móvil los chips ya se
          identifican con su propio badge IA y la fila gana altura. */}
      <div className="mb-2 hidden items-center gap-2 sm:flex">
        <Badge variant="ai" size="sm" icon>
          IA
        </Badge>
        <span className="text-xs text-muted-foreground">{t("chats.aiSmartSuggestions")}</span>
      </div>
      <div className="-mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
        {suggestions.map((suggestion, index) => (
          <Button
            key={index}
            variant="outline"
            size="sm"
            className="h-7 shrink-0 gap-1 bg-transparent text-xs"
            onClick={() => onSuggestionClick(suggestion)}
          >
            <Badge variant="ai" size="sm">
              IA
            </Badge>
            {suggestion}
          </Button>
        ))}
      </div>
    </div>
  )
}
