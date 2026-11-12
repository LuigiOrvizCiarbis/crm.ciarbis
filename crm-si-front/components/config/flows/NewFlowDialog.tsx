"use client"

import { useRouter } from "next/navigation"
import { CalendarClock, FilePlus2, UserRoundPlus, type LucideIcon } from "lucide-react"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useTranslation } from "@/hooks/useTranslation"

type Preset = "blank" | "lead_capture" | "appointment"

const PRESETS: { id: Preset; icon: LucideIcon; key: string }[] = [
  { id: "blank", icon: FilePlus2, key: "blank" },
  { id: "lead_capture", icon: UserRoundPlus, key: "leadCapture" },
  { id: "appointment", icon: CalendarClock, key: "appointment" },
]

interface NewFlowDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
}

/** Punto de entrada al constructor visual: elegir con qué arranca el formulario. */
export function NewFlowDialog({ open, onOpenChange, channelId }: NewFlowDialogProps) {
  const { t } = useTranslation()
  const router = useRouter()

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t("flows.settings.new.title")}</DialogTitle>
          <DialogDescription>{t("flows.settings.new.description")}</DialogDescription>
        </DialogHeader>
        <div className="grid gap-3 sm:grid-cols-3">
          {PRESETS.map(({ id, icon: Icon, key }) => (
            <button
              key={id}
              type="button"
              className="flex flex-col items-start gap-2 rounded-lg border p-4 text-left transition-colors hover:border-primary hover:bg-accent focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
              onClick={() => {
                onOpenChange(false)
                router.push(`/configuracion/formularios/nuevo?channel=${channelId}&preset=${id}`)
              }}
            >
              <Icon className="size-5 text-primary" aria-hidden />
              <span className="text-sm font-medium">{t(`flows.settings.new.${key}.title`)}</span>
              <span className="text-xs leading-5 text-muted-foreground">
                {t(`flows.settings.new.${key}.description`)}
              </span>
            </button>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  )
}
