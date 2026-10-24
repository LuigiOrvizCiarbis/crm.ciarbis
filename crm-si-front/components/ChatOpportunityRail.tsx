"use client"

import { Briefcase, ChevronLeft, ChevronRight, Loader2, Plus } from "lucide-react"
import type { PipelineStage } from "@/lib/api/pipeline"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"

export type ChatOpportunity = {
  id: number
  title: string
  value: number | string | null
  pipeline_id: number
  pipeline_stage_id: number | null
  pipeline?: { id: number; name: string; is_default?: boolean } | null
}

const formatOpportunityValue = (value: ChatOpportunity["value"]) => {
  const amount = Number(value)
  return amount > 0 ? `$${new Intl.NumberFormat("es-AR").format(Math.round(amount))}` : null
}

const FALLBACK_STAGE_COLOR = "var(--muted-foreground)"

const normalize = (text: string) => text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase()

/**
 * Título propio de la oportunidad, o null si es el que arma el sistema
 * ("Oportunidad - {contacto}"): dentro del chat del contacto no dice nada.
 */
const customTitle = (title: string, contactName?: string) => {
  const rest = normalize(title).replace(/^oportunidad\s*[-–—:]?\s*/, "")
  if (!rest) return null
  if (contactName && rest === normalize(contactName)) return null
  return title.trim()
}

interface ChatOpportunityRailProps {
  opportunities: ChatOpportunity[]
  stagesByPipeline: Record<number, PipelineStage[]>
  pendingOpportunityId: number | null
  /** Para ocultar el título genérico "Oportunidad - {contacto}". */
  contactName?: string
  onMove: (opportunity: ChatOpportunity, stageId: number) => void
  onNew: () => void
}

/**
 * Riel de oportunidades del chat: una ficha por oportunidad con la pista de
 * etapas de su pipeline. Cada segmento de la pista es un botón que mueve la
 * oportunidad a esa etapa; `›` avanza a la siguiente.
 */
export function ChatOpportunityRail({
  opportunities,
  stagesByPipeline,
  pendingOpportunityId,
  contactName,
  onMove,
  onNew,
}: ChatOpportunityRailProps) {
  const { t } = useTranslation()

  return (
    <div
      className="-mx-1.5 mt-1.5 flex snap-x items-stretch gap-1.5 overflow-x-auto border-t border-border/60 px-1.5 pt-1.5 [scrollbar-width:none] sm:-mx-2 sm:px-2"
      aria-label={t("chats.opportunitiesOfChat")}
      role="list"
    >
      {opportunities.map((opportunity) => (
        <OpportunityTicket
          key={opportunity.id}
          opportunity={opportunity}
          stages={stagesByPipeline[opportunity.pipeline_id] ?? []}
          pending={pendingOpportunityId === opportunity.id}
          contactName={contactName}
          onMove={(stageId) => onMove(opportunity, stageId)}
        />
      ))}
      {/* Sólo ícono: el riel ya dice que son oportunidades. */}
      <button
        type="button"
        onClick={onNew}
        title={t("chats.newOpportunity")}
        aria-label={t("chats.newOpportunity")}
        className="flex w-8 shrink-0 snap-start items-center justify-center self-center rounded-lg py-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        <Plus className="h-4 w-4" />
      </button>
    </div>
  )
}

function OpportunityTicket({
  opportunity,
  stages,
  pending,
  contactName,
  onMove,
}: {
  opportunity: ChatOpportunity
  stages: PipelineStage[]
  pending: boolean
  contactName?: string
  onMove: (stageId: number) => void
}) {
  const { t } = useTranslation()
  const currentIndex = stages.findIndex((stage) => stage.id === opportunity.pipeline_stage_id)
  const current = currentIndex >= 0 ? stages[currentIndex] : undefined
  const next = currentIndex >= 0 ? stages[currentIndex + 1] : stages[0]
  const previous = currentIndex > 0 ? stages[currentIndex - 1] : undefined
  const accent = current?.color ?? FALLBACK_STAGE_COLOR
  const value = formatOpportunityValue(opportunity.value)
  const title = customTitle(opportunity.title, contactName)
  const pipelineName = opportunity.pipeline?.name ?? opportunity.title
  const details = [title, value].filter(Boolean).join(" · ")

  return (
    <div
      role="listitem"
      className={cn(
        "relative flex shrink-0 snap-start items-center gap-2.5 overflow-hidden rounded-lg border border-border/70 bg-muted/30 py-1.5 pl-3 pr-1 transition-opacity",
        pending && "opacity-60",
      )}
    >
      {/* Filo de color: la etapa actual se lee antes que el texto. */}
      <span aria-hidden="true" className="absolute inset-y-0 left-0 w-[3px] transition-colors duration-300" style={{ backgroundColor: accent }} />

      {/* El pipeline encabeza la ficha; el título sólo si es propio. */}
      <div className="flex min-w-0 max-w-[200px] flex-col leading-tight" title={opportunity.title}>
        <span className="flex min-w-0 items-center gap-1.5 text-[13px] font-medium text-foreground">
          <Briefcase className="h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
          <span className="truncate">{pipelineName}</span>
        </span>
        {details && <span className="truncate pl-[18px] text-[11px] tabular-nums text-muted-foreground">{details}</span>}
      </div>

      <StepButton
        direction="previous"
        target={previous}
        disabled={pending}
        emptyLabel={t("chats.firstStage")}
        onMove={onMove}
      />

      <div className="-mx-1.5 flex flex-col gap-1">
        <div className="flex items-center gap-[3px]" role="group" aria-label={t("chats.opportunityStageLabel")}>
          {stages.map((stage, index) => {
            const reached = currentIndex >= 0 && index <= currentIndex
            const isCurrent = index === currentIndex
            return (
              <button
                key={stage.id}
                type="button"
                disabled={pending || isCurrent}
                onClick={() => onMove(stage.id)}
                title={stage.name}
                aria-label={t("chats.moveOpportunityTo", { stage: stage.name })}
                aria-current={isCurrent ? "step" : undefined}
                className="group/seg py-1.5 disabled:cursor-default"
              >
                <span
                  className={cn(
                    "block h-1.5 w-4 rounded-full transition-[background-color,transform] duration-300 group-hover/seg:scale-y-150 group-disabled/seg:scale-y-100",
                    !reached && "bg-border group-hover/seg:bg-muted-foreground/50",
                    isCurrent && "w-6",
                  )}
                  style={reached ? { backgroundColor: stage.color ?? FALLBACK_STAGE_COLOR } : undefined}
                />
              </button>
            )
          })}
        </div>
        <span className="flex items-center gap-1.5 text-[11px] font-medium" style={{ color: accent }}>
          {pending ? <Loader2 className="h-2.5 w-2.5 animate-spin" aria-hidden="true" /> : null}
          <span className="truncate text-foreground/90">{current?.name ?? t("chats.select")}</span>
        </span>
      </div>

      <StepButton
        direction="next"
        target={next}
        disabled={pending}
        emptyLabel={t("chats.lastStage")}
        onMove={onMove}
      />
    </div>
  )
}

/** Avanza o retrocede una etapa; deshabilitado en los extremos de la pista. */
function StepButton({
  direction,
  target,
  disabled,
  emptyLabel,
  onMove,
}: {
  direction: "previous" | "next"
  target?: PipelineStage
  disabled: boolean
  emptyLabel: string
  onMove: (stageId: number) => void
}) {
  const { t } = useTranslation()
  const Icon = direction === "next" ? ChevronRight : ChevronLeft
  const label = target ? t("chats.moveOpportunityTo", { stage: target.name }) : emptyLabel

  return (
    <button
      type="button"
      disabled={!target || disabled}
      onClick={() => target && onMove(target.id)}
      title={label}
      aria-label={label}
      className="flex h-8 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-30"
    >
      <Icon className="h-4 w-4" />
    </button>
  )
}
