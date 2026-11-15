"use client"

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import type { WhatsAppFlow } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

import { FlowClosingPanel } from "./FlowClosingPanel"
import { FlowOpeningPanel } from "./FlowOpeningPanel"

export type FlowJourneyPart = "opening" | "closing"

interface FlowJourneyDialogProps {
  flow: WhatsAppFlow | null
  part: FlowJourneyPart
  channelId: number
  onOpenChange: (open: boolean) => void
  onFlowChange: (flow: WhatsAppFlow) => void
}

/** Apertura o Cierre desde la lista, con los mismos paneles que el constructor. */
export function FlowJourneyDialog({ flow, part, channelId, onOpenChange, onFlowChange }: FlowJourneyDialogProps) {
  const { t } = useTranslation()

  return (
    <Dialog open={flow !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[92vh] max-w-lg overflow-y-auto">
        {flow ? (
          <>
            <DialogHeader>
              <DialogTitle>{t(`flows.journey.${part}.dialogTitle`, { name: flow.name })}</DialogTitle>
              <DialogDescription>{t(`flows.journey.${part}.description`)}</DialogDescription>
            </DialogHeader>
            {part === "opening" ? (
              <FlowOpeningPanel key={flow.id} flow={flow} channelId={channelId} onFlowChange={onFlowChange} />
            ) : (
              <FlowClosingPanel
                key={flow.id}
                flow={flow}
                channelId={channelId}
                fields={flow.response_fields}
                onFlowChange={onFlowChange}
              />
            )}
          </>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
