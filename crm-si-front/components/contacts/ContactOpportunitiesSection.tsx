"use client"

import { useState } from "react"
import { Plus } from "lucide-react"
import { Button } from "@/components/ui/button"
import { ChatOpportunityRail, type ChatOpportunity } from "@/components/ChatOpportunityRail"
import { needsSaleValue, useSaleValueGuard } from "@/components/sale-value-guard"
import { useIsMobile } from "@/hooks/use-mobile"
import { usePermission } from "@/hooks/usePermission"
import { usePipelineCatalog } from "@/hooks/usePipelineCatalog"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactOpportunity } from "@/lib/api/contacts"
import { StagePickerPanel } from "./ContactStagePicker"
import { useContactOpportunityActions, type OpportunitiesUpdate, type OpportunityContact } from "./useContactOpportunityActions"

interface ContactOpportunitiesSectionProps {
  contact: OpportunityContact
  opportunities: ContactOpportunity[]
  onChange: (update: OpportunitiesUpdate) => void
  onSaved?: () => void
}

/**
 * Oportunidades en el perfil del contacto: la misma pista de etapas que en
 * chats, y "nueva oportunidad" se despliega en el lugar, sin otro diálogo.
 */
export function ContactOpportunitiesSection({ contact, opportunities, onChange, onSaved }: ContactOpportunitiesSectionProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const canChange = usePermission("opportunities.change_stage")
  const canCreate = usePermission("opportunities.create")
  const [creating, setCreating] = useState(false)
  const { catalog } = usePipelineCatalog(opportunities.length > 0)
  const { confirmSaleValue, dialog: saleValueDialog } = useSaleValueGuard()
  const actions = useContactOpportunityActions({ contact, onChange, onSaved })

  const stagesByPipeline = Object.fromEntries((catalog ?? []).map((entry) => [entry.pipeline.id, entry.stages]))
  const railItems: ChatOpportunity[] = opportunities.map((item) => ({
    id: item.id,
    title: item.title,
    value: item.value,
    pipeline_id: item.pipeline_id,
    pipeline_stage_id: item.pipeline_stage?.id ?? null,
    created_at: item.created_at,
    pipeline: { id: item.pipeline_id, name: item.pipeline_name },
  }))

  const handleMove = async (railItem: ChatOpportunity, stageId: number) => {
    const opportunity = opportunities.find((item) => item.id === railItem.id)
    const stage = stagesByPipeline[railItem.pipeline_id]?.find((item) => item.id === stageId)
    if (!opportunity || !stage || !canChange) return
    let value: number | undefined
    if (needsSaleValue(stage.outcome, opportunity.value)) {
      const decision = await confirmSaleValue(contact.name)
      if (!decision) return
      value = decision.value
    }
    void actions.move(opportunity, stage, value)
  }

  return (
    <section className="space-y-2" aria-label={t("contactsPage.stage.opportunities")}>
      <p className="text-xs text-muted-foreground">{t("contactsPage.stage.opportunities")}</p>

      {opportunities.length > 0 ? (
        <ChatOpportunityRail
          opportunities={railItems}
          stagesByPipeline={stagesByPipeline}
          pendingOpportunityId={typeof actions.pendingId === "number" ? actions.pendingId : null}
          contactName={contact.name}
          onMove={(item, stageId) => void handleMove(item, stageId)}
          onNew={() => canCreate && setCreating(true)}
          className="flex-col overflow-visible"
        />
      ) : !creating && (
        <div className="flex items-center justify-between gap-3">
          <p className="text-sm text-muted-foreground">{t("contactsPage.stage.sheetEmpty")}</p>
          {canCreate && (
            <Button variant="outline" size="sm" className="shrink-0 gap-1.5" onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden="true" />
              {t("contactsPage.stage.add")}
            </Button>
          )}
        </div>
      )}

      {creating && (
        <div className="rounded-lg border border-border">
          <StagePickerPanel
            opportunities={opportunities}
            actions={actions}
            canChange={canChange}
            canCreate={canCreate}
            touch={isMobile}
            startInCreate
            onDone={() => setCreating(false)}
          />
        </div>
      )}
      {saleValueDialog}
    </section>
  )
}
