"use client"

import { useState, type ReactNode } from "react"
import { useDeleteOpportunity } from "@/components/opportunities/useDeleteOpportunity"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactOpportunity } from "@/lib/api/contacts"
import { createOpportunity, updateOpportunityStage } from "@/lib/api/opportunities"
import type { Pipeline, PipelineStage } from "@/lib/api/pipeline"

export type OpportunitiesUpdate = (current: ContactOpportunity[]) => ContactOpportunity[]

export interface OpportunityContact {
  id: number
  name: string
  conversations?: { id: number }[]
}

/** La que representa al contacto: la abierta movida más recientemente; si no hay, la última cerrada. */
export function primaryOpportunity(opportunities: ContactOpportunity[] | undefined): ContactOpportunity | null {
  if (!opportunities?.length) return null
  return opportunities.find((item) => item.status === "open") ?? opportunities[0]
}

/** Si el contacto entra en el filtro de etapas: por alguna de sus oportunidades, o "none" si no tiene. */
export function matchesStageKeys(opportunities: ContactOpportunity[] | undefined, selected: string[]): boolean {
  if (selected.length === 0) return true
  if (!opportunities?.length) return selected.includes("none")
  return opportunities.some((item) => item.pipeline_stage && selected.includes(String(item.pipeline_stage.id)))
}

type RawOpportunity = {
  id: number
  title: string
  pipeline_id: number
  conversation_id?: number | null
  status: ContactOpportunity["status"] | "archived"
  value?: number | string | null
  created_at: string
  updated_at: string
  pipeline?: { name: string } | null
}

/**
 * Las respuestas de /api/opportunities traen la etapa sin color ni resultado:
 * el resumen se arma con la etapa elegida, que es donde quedó la oportunidad.
 */
function toContactOpportunity(raw: RawOpportunity, pipelineName: string, stage: PipelineStage): ContactOpportunity {
  return {
    id: raw.id,
    title: raw.title,
    pipeline_id: raw.pipeline_id,
    pipeline_name: raw.pipeline?.name ?? pipelineName,
    conversation_id: raw.conversation_id ?? null,
    status: raw.status === "archived" ? "open" : raw.status,
    value: raw.value ?? null,
    created_at: raw.created_at,
    updated_at: raw.updated_at,
    pipeline_stage: { id: stage.id, name: stage.name, color: stage.color, outcome: stage.outcome },
  }
}

/** La movida pasa al frente: la lista se mantiene ordenada por último movimiento. */
function withFirst(list: ContactOpportunity[], item: ContactOpportunity): ContactOpportunity[] {
  return [item, ...list.filter((other) => other.id !== item.id)]
}

export interface ContactOpportunityActions {
  move: (opportunity: ContactOpportunity, stage: PipelineStage, value?: number) => Promise<boolean>
  create: (pipeline: Pipeline, stage: PipelineStage, value?: number) => Promise<boolean>
  /** Oportunidad que se está guardando, o "new" mientras se crea una. */
  pendingId: number | "new" | null
  /** Pide confirmación y borra; ausente sin permiso para borrar. */
  requestDelete?: (opportunity: ContactOpportunity) => void
  /** Confirmación del borrado; se renderiza fuera del selector, que se cierra antes. */
  deletionDialog: ReactNode
}

/**
 * Mover y crear oportunidades de un contacto. El cambio de etapa es optimista
 * y vuelve atrás si el backend lo rechaza; crear espera al servidor porque
 * la fila necesita el id real.
 */
export function useContactOpportunityActions({
  contact,
  onChange,
  onSaved,
}: {
  contact: OpportunityContact
  onChange: (update: OpportunitiesUpdate) => void
  onSaved?: () => void
}): ContactOpportunityActions {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [pendingId, setPendingId] = useState<number | "new" | null>(null)
  const deletion = useDeleteOpportunity((opportunityId) => {
    onChange((current) => current.filter((item) => item.id !== opportunityId))
    onSaved?.()
  })

  const requestDelete = (opportunity: ContactOpportunity) => deletion.requestDelete({
    id: opportunity.id,
    contactName: contact.name,
    summary: [opportunity.pipeline_name, opportunity.pipeline_stage?.name].filter(Boolean).join(" · "),
    won: opportunity.status === "won",
  })

  const successTitle = (stage: PipelineStage, fallback: string) =>
    stage.outcome === "won"
      ? t("pipeline.outcomeToast.won")
      : stage.outcome === "lost" ? t("pipeline.outcomeToast.lost") : fallback

  const move = async (opportunity: ContactOpportunity, stage: PipelineStage, value?: number): Promise<boolean> => {
    if (opportunity.pipeline_stage?.id === stage.id) return true

    onChange((current) => withFirst(current, {
      ...opportunity,
      pipeline_stage: { id: stage.id, name: stage.name, color: stage.color, outcome: stage.outcome },
      status: stage.outcome ?? "open",
      updated_at: new Date().toISOString(),
      ...(value !== undefined ? { value } : {}),
    }))
    setPendingId(opportunity.id)
    try {
      const saved = await updateOpportunityStage(opportunity.id, stage.id, stage.pipeline_id, value)
      onChange((current) => current.map((item) => (item.id === opportunity.id ? toContactOpportunity(saved, opportunity.pipeline_name, stage) : item)))
      addToast({ type: "success", title: successTitle(stage, t("contactsPage.stage.updated")), description: `${contact.name} → ${stage.name}` })
      onSaved?.()
      return true
    } catch (error) {
      onChange((current) => current.map((item) => (item.id === opportunity.id ? opportunity : item)))
      addToast({ type: "error", title: t("chats.stageUpdateError"), description: error instanceof Error ? error.message : undefined })
      return false
    } finally {
      setPendingId(null)
    }
  }

  const create = async (pipeline: Pipeline, stage: PipelineStage, value?: number): Promise<boolean> => {
    const conversationId = contact.conversations?.[0]?.id ?? null
    setPendingId("new")
    try {
      const saved = await createOpportunity({
        contact_id: contact.id,
        conversation_id: conversationId,
        pipeline_id: pipeline.id,
        pipeline_stage_id: stage.id,
        title: `Oportunidad - ${contact.name}`,
        source_type: conversationId ? "conversation" : "manual",
        ...(value !== undefined ? { value } : {}),
      })
      onChange((current) => withFirst(current, toContactOpportunity(saved, pipeline.name, stage)))
      addToast({ type: "success", title: successTitle(stage, t("contactsPage.stage.created")), description: `${pipeline.name} · ${stage.name}` })
      onSaved?.()
      return true
    } catch (error) {
      addToast({ type: "error", title: t("contactsPage.stage.createError"), description: error instanceof Error ? error.message : undefined })
      return false
    } finally {
      setPendingId(null)
    }
  }

  return {
    move,
    create,
    pendingId,
    requestDelete: deletion.canDelete ? requestDelete : undefined,
    deletionDialog: deletion.dialog,
  }
}
