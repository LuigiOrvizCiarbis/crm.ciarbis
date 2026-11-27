"use client"

import { useId, useState, type CSSProperties } from "react"
import { ArrowLeft, Check, CheckCircle2, ChevronDown, Loader2, Plus, Trash2, XCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Drawer, DrawerContent, DrawerTitle } from "@/components/ui/drawer"
import { Skeleton } from "@/components/ui/skeleton"
import { needsSaleValue } from "@/components/sale-value-guard"
import { useIsMobile } from "@/hooks/use-mobile"
import { usePermission } from "@/hooks/usePermission"
import { usePipelineCatalog, type PipelineCatalogEntry } from "@/hooks/usePipelineCatalog"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactOpportunity, ContactOpportunityStage } from "@/lib/api/contacts"
import type { Pipeline, PipelineStage } from "@/lib/api/pipeline"
import { cn } from "@/lib/utils"
import {
  primaryOpportunity,
  useContactOpportunityActions,
  type ContactOpportunityActions,
  type OpportunitiesUpdate,
  type OpportunityContact,
} from "./useContactOpportunityActions"

const FALLBACK_STAGE_COLOR = "var(--muted-foreground)"

/** Punto de color de la etapa; Ganado y Perdido llevan ícono para no depender sólo del matiz. */
export function StageMark({ stage, className }: { stage: Pick<ContactOpportunityStage, "color" | "outcome"> | null; className?: string }) {
  const color = stage?.color ?? FALLBACK_STAGE_COLOR
  if (stage?.outcome === "won") return <CheckCircle2 aria-hidden="true" className={cn("size-3.5 shrink-0", className)} style={{ color }} />
  if (stage?.outcome === "lost") return <XCircle aria-hidden="true" className={cn("size-3.5 shrink-0", className)} style={{ color }} />
  return <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", className)} style={{ backgroundColor: color }} />
}

interface ContactStagePickerProps {
  contact: OpportunityContact
  opportunities: ContactOpportunity[]
  onChange: (update: OpportunitiesUpdate) => void
  /** Después de guardar: métricas y conteos por etapa quedan viejos. */
  onSaved?: () => void
  /** "row": sin oportunidad, la acción aparece al pasar o enfocar la fila. "card": siempre visible (táctil). */
  variant?: "row" | "card"
}

/**
 * Etapa del contacto en su fila: el chip de la oportunidad principal abre un
 * selector para moverla, elegir otra oportunidad o crear una nueva.
 */
export function ContactStagePicker({ contact, opportunities, onChange, onSaved, variant = "row" }: ContactStagePickerProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const canChange = usePermission("opportunities.change_stage")
  const canCreate = usePermission("opportunities.create")
  const [open, setOpen] = useState(false)
  const actions = useContactOpportunityActions({ contact, onChange, onSaved })
  const primary = primaryOpportunity(opportunities)
  const others = opportunities.length - 1

  if (!primary && !canCreate) return <span className="text-sm text-muted-foreground">—</span>

  const stageName = primary?.pipeline_stage?.name ?? t("contactsPage.stage.noStage")
  const pending = actions.pendingId !== null && (actions.pendingId === primary?.id || actions.pendingId === "new")
  const chip = primary ? (
    <>
      <span
        className="inline-flex min-w-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium text-foreground transition-[background-color] duration-200 motion-reduce:transition-none"
        style={{ backgroundColor: `color-mix(in oklch, ${primary.pipeline_stage?.color ?? FALLBACK_STAGE_COLOR} 14%, transparent)` } as CSSProperties}
      >
        {pending ? <Loader2 className="size-3 shrink-0 animate-spin" aria-hidden="true" /> : <StageMark stage={primary.pipeline_stage} />}
        <span className="truncate">{stageName}</span>
      </span>
      {others > 0 && (
        <span className="shrink-0 text-xs tabular-nums text-muted-foreground" aria-label={t(`contactsPage.stage.more_${others === 1 ? "one" : "other"}`, { count: others })}>
          +{others}
        </span>
      )}
    </>
  ) : null

  if (primary && !canChange && !canCreate) {
    return (
      <span className="flex min-w-0 items-center gap-1.5" title={`${primary.pipeline_name} · ${stageName}`}>
        {chip}
      </span>
    )
  }

  const trigger = primary ? (
    <button
      type="button"
      onClick={isMobile ? () => setOpen(true) : undefined}
      title={`${primary.pipeline_name} · ${stageName}`}
      aria-label={t("contactsPage.stage.changeFor", { name: contact.name, stage: stageName })}
      aria-haspopup="dialog"
      className="group/stage flex min-w-0 max-w-full items-center gap-1.5 rounded-md px-1 py-1 outline-none transition-colors hover:bg-muted/60 focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=open]:bg-muted/60 pointer-coarse:min-h-10"
    >
      {chip}
      <ChevronDown
        aria-hidden="true"
        className="size-3 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover/stage:opacity-100 group-focus-visible/stage:opacity-100 group-data-[state=open]/stage:opacity-100 pointer-coarse:opacity-100"
      />
    </button>
  ) : (
    <button
      type="button"
      onClick={isMobile ? () => setOpen(true) : undefined}
      aria-label={t("contactsPage.stage.addFor", { name: contact.name })}
      aria-haspopup="dialog"
      className={cn(
        "flex items-center gap-1 rounded-md px-1.5 py-1 text-xs text-muted-foreground outline-none transition-[color,opacity] hover:text-foreground focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=open]:opacity-100 pointer-coarse:min-h-10",
        variant === "row" && "opacity-0 group-hover/row:opacity-100 pointer-coarse:opacity-100",
      )}
    >
      {actions.pendingId === "new" ? <Loader2 className="size-3.5 animate-spin" aria-hidden="true" /> : <Plus className="size-3.5" aria-hidden="true" />}
      {t("contactsPage.stage.add")}
    </button>
  )

  const panel = (
    <StagePickerPanel
      opportunities={opportunities}
      actions={actions}
      canChange={canChange}
      canCreate={canCreate}
      touch={isMobile}
      onDone={() => setOpen(false)}
    />
  )

  if (isMobile) {
    return (
      <>
        {trigger}
        <Drawer open={open} onOpenChange={setOpen}>
          <DrawerContent className="max-h-[85vh]">
            <DrawerTitle className="sr-only">{t("contactsPage.stage.pickerTitle", { name: contact.name })}</DrawerTitle>
            <div className="min-h-0 flex-1 overflow-y-auto pb-4">{open && panel}</div>
          </DrawerContent>
        </Drawer>
        {actions.deletionDialog}
      </>
    )
  }

  return (
    <>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>{trigger}</PopoverTrigger>
        <PopoverContent
          align="start"
          className="w-72 p-0"
          aria-label={t("contactsPage.stage.pickerTitle", { name: contact.name })}
          // La tabla no debe recibir el clic que abre o cierra el selector.
          onClick={(event) => event.stopPropagation()}
        >
          {panel}
        </PopoverContent>
      </Popover>
      {actions.deletionDialog}
    </>
  )
}

type Choice =
  | { kind: "move"; opportunity: ContactOpportunity; stage: PipelineStage }
  | { kind: "create"; pipeline: Pipeline; stage: PipelineStage }

type View = { kind: "move"; opportunityId: number } | { kind: "create"; pipelineId: number | null }

interface StagePickerPanelProps {
  opportunities: ContactOpportunity[]
  actions: ContactOpportunityActions
  canChange: boolean
  canCreate: boolean
  touch: boolean
  onDone: () => void
  /** Abrir directo en "nueva oportunidad"; volver cierra en vez de ir a la lista de etapas. */
  startInCreate?: boolean
}

export function StagePickerPanel({ opportunities, actions, canChange, canCreate, touch, onDone, startInCreate = false }: StagePickerPanelProps) {
  const { t } = useTranslation()
  const { catalog, failed, retry } = usePipelineCatalog(true)
  const primary = primaryOpportunity(opportunities)
  const [view, setView] = useState<View>(() => (
    primary && canChange && !startInCreate ? { kind: "move", opportunityId: primary.id } : { kind: "create", pipelineId: null }
  ))
  const [valueStep, setValueStep] = useState<Choice | null>(null)
  const [creatingStageId, setCreatingStageId] = useState<number | null>(null)

  const commit = async (choice: Choice, value?: number) => {
    if (choice.kind === "move") {
      // Optimista: el chip ya cambió en la fila; un error vuelve atrás con un aviso.
      onDone()
      void actions.move(choice.opportunity, choice.stage, value)
      return
    }
    setValueStep(null)
    setCreatingStageId(choice.stage.id)
    const created = await actions.create(choice.pipeline, choice.stage, value)
    setCreatingStageId(null)
    if (created) onDone()
  }

  const choose = (choice: Choice) => {
    const currentValue = choice.kind === "move" ? choice.opportunity.value : null
    if (needsSaleValue(choice.stage.outcome, currentValue)) setValueStep(choice)
    else void commit(choice)
  }

  if (valueStep) {
    return <SaleValueStep stageName={valueStep.stage.name} onBack={() => setValueStep(null)} onSubmit={(value) => void commit(valueStep, value)} />
  }

  if (failed) {
    return (
      <div className="flex flex-col items-center gap-3 px-4 py-6 text-center text-sm text-muted-foreground">
        <p>{t("chats.stagesLoadError")}</p>
        <Button variant="outline" size="sm" onClick={retry}>{t("chats.retry")}</Button>
      </div>
    )
  }

  if (!catalog) {
    return (
      <div role="status" aria-label={t("chats.stageFilter.loading")} className="space-y-2 p-3">
        <Skeleton className="h-8 w-full" />
        {[0, 1, 2, 3].map((row) => (
          <div key={row} className="flex items-center gap-2 px-1">
            <Skeleton className="size-2 rounded-full" />
            <Skeleton className="h-3.5" style={{ width: `${70 - row * 12}%` }} />
          </div>
        ))}
      </div>
    )
  }

  const entryFor = (pipelineId: number | null): PipelineCatalogEntry | undefined =>
    catalog.find((entry) => entry.pipeline.id === pipelineId)
    ?? catalog.find((entry) => entry.pipeline.is_default)
    ?? catalog[0]

  if (view.kind === "move") {
    const opportunity = opportunities.find((item) => item.id === view.opportunityId) ?? primary
    if (!opportunity) return null
    const stages = catalog.find((entry) => entry.pipeline.id === opportunity.pipeline_id)?.stages ?? []

    return (
      <div className="flex flex-col">
        {opportunities.length > 1 && (
          <OpportunityList
            opportunities={opportunities}
            selectedId={opportunity.id}
            touch={touch}
            onSelect={(id) => setView({ kind: "move", opportunityId: id })}
          />
        )}
        <StageCommand
          key={opportunity.id}
          stages={stages}
          currentId={opportunity.pipeline_stage?.id ?? null}
          pendingId={null}
          touch={touch}
          onSelect={(stage) => choose({ kind: "move", opportunity, stage })}
        />
        {(canCreate || actions.requestDelete) && (
          <div className="border-t border-border p-1">
            {canCreate && (
              <button
                type="button"
                onClick={() => setView({ kind: "create", pipelineId: opportunity.pipeline_id })}
                className={cn(
                  "flex w-full items-center gap-2 rounded-sm px-2 text-left text-sm text-muted-foreground outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:bg-muted focus-visible:text-foreground",
                  touch ? "py-3" : "py-1.5",
                )}
              >
                <Plus className="size-4" aria-hidden="true" />
                {t("contactsPage.stage.newIn")}
              </button>
            )}
            {actions.requestDelete && (
              <button
                type="button"
                onClick={() => {
                  // El selector se cierra primero: la confirmación vive fuera de él.
                  onDone()
                  actions.requestDelete?.(opportunity)
                }}
                className={cn(
                  "flex w-full items-center gap-2 rounded-sm px-2 text-left text-sm text-destructive outline-none transition-colors hover:bg-destructive/10 focus-visible:bg-destructive/10",
                  touch ? "py-3" : "py-1.5",
                )}
              >
                <Trash2 className="size-4" aria-hidden="true" />
                {t("pipeline.delete.action")}
              </button>
            )}
          </div>
        )}
      </div>
    )
  }

  const entry = entryFor(view.pipelineId)
  const canGoBack = startInCreate || Boolean(primary && canChange)

  return (
    <div className="flex flex-col">
      <div className="flex items-center gap-1 px-2 pt-2">
        {canGoBack && (
          <Button
            variant="ghost"
            size="sm"
            className="size-7 p-0"
            onClick={() => {
              if (startInCreate) onDone()
              else if (primary) setView({ kind: "move", opportunityId: primary.id })
            }}
            aria-label={t("contactsPage.stage.back")}
          >
            <ArrowLeft className="size-4" aria-hidden="true" />
          </Button>
        )}
        <p className={cn("text-sm font-semibold", !canGoBack && "px-1")}>
          {opportunities.length ? t("contactsPage.stage.newTitle") : t("contactsPage.stage.addTitle")}
        </p>
      </div>

      {catalog.length > 1 && (
        <div role="tablist" aria-label={t("contactsPage.stage.pipelines")} className="flex gap-1 overflow-x-auto px-3 pt-2 [scrollbar-width:none]">
          {catalog.map(({ pipeline }) => {
            const active = pipeline.id === entry?.pipeline.id
            return (
              <button
                key={pipeline.id}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => setView({ kind: "create", pipelineId: pipeline.id })}
                className={cn(
                  "shrink-0 rounded-full border px-3 text-xs font-medium outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50",
                  touch ? "py-1.5" : "py-1",
                  active ? "border-primary bg-primary/10 text-foreground" : "border-border text-muted-foreground hover:text-foreground",
                )}
              >
                {pipeline.name}
              </button>
            )
          })}
        </div>
      )}

      {entry ? (
        <StageCommand
          key={entry.pipeline.id}
          stages={entry.stages}
          currentId={null}
          pendingId={creatingStageId}
          touch={touch}
          onSelect={(stage) => {
            if (creatingStageId === null) choose({ kind: "create", pipeline: entry.pipeline, stage })
          }}
        />
      ) : (
        <p className="px-4 py-6 text-center text-sm text-muted-foreground">{t("contactsPage.stage.noPipelines")}</p>
      )}
    </div>
  )
}

function OpportunityList({
  opportunities,
  selectedId,
  touch,
  onSelect,
}: {
  opportunities: ContactOpportunity[]
  selectedId: number
  touch: boolean
  onSelect: (id: number) => void
}) {
  const { t, language } = useTranslation()
  // Dos oportunidades del mismo embudo se distinguen por la fecha en que se abrieron.
  const perPipeline = opportunities.reduce<Record<number, number>>((counts, item) => {
    counts[item.pipeline_id] = (counts[item.pipeline_id] ?? 0) + 1
    return counts
  }, {})

  return (
    <div className="border-b border-border p-1">
      <p className="px-2 pb-1 pt-1.5 text-xs font-medium text-muted-foreground">{t("contactsPage.stage.opportunities")}</p>
      <div role="radiogroup" aria-label={t("contactsPage.stage.opportunities")}>
        {opportunities.map((opportunity) => {
          const selected = opportunity.id === selectedId
          const since = perPipeline[opportunity.pipeline_id] > 1
            ? t("chats.opportunitySince", { date: new Date(opportunity.created_at).toLocaleDateString(language, { day: "numeric", month: "short" }) })
            : null
          return (
            <button
              key={opportunity.id}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => onSelect(opportunity.id)}
              className={cn(
                "flex w-full items-center gap-2 rounded-sm px-2 text-left text-sm outline-none transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50",
                touch ? "py-2.5" : "py-1.5",
                selected && "bg-muted",
              )}
            >
              <StageMark stage={opportunity.pipeline_stage} />
              <span className="min-w-0 flex-1 truncate">
                <span className="font-medium">{opportunity.pipeline_name}</span>
                <span className="text-muted-foreground"> · {opportunity.pipeline_stage?.name ?? t("contactsPage.stage.noStage")}</span>
              </span>
              {since && <span className="shrink-0 text-xs text-muted-foreground">{since}</span>}
            </button>
          )
        })}
      </div>
    </div>
  )
}

function StageCommand({
  stages,
  currentId,
  pendingId,
  touch,
  onSelect,
}: {
  stages: PipelineStage[]
  currentId: number | null
  pendingId: number | null
  touch: boolean
  onSelect: (stage: PipelineStage) => void
}) {
  const { t } = useTranslation()
  // El resaltado arranca en la etapa actual: flecha arriba/abajo mueve desde ahí.
  const [highlighted, setHighlighted] = useState(currentId !== null ? String(currentId) : String(stages[0]?.id ?? ""))

  if (stages.length === 0) {
    return <p className="px-4 py-6 text-center text-sm text-muted-foreground">{t("contactsPage.stage.noStages")}</p>
  }

  return (
    <Command loop value={highlighted} onValueChange={setHighlighted} className="bg-transparent">
      <CommandInput placeholder={t("contactsPage.stage.search")} autoFocus={!touch} />
      <CommandList className="max-h-[288px]">
        <CommandEmpty>{t("contactsPage.stage.noMatch")}</CommandEmpty>
        {stages.map((stage) => {
          const isCurrent = stage.id === currentId
          return (
            <CommandItem
              key={stage.id}
              value={String(stage.id)}
              keywords={[stage.name]}
              onSelect={() => {
                if (!isCurrent) onSelect(stage)
              }}
              aria-current={isCurrent ? "true" : undefined}
              className={cn("gap-2.5", touch && "py-3")}
            >
              <StageMark stage={stage} />
              <span className="min-w-0 flex-1 truncate">{stage.name}</span>
              {pendingId === stage.id ? (
                <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" aria-hidden="true" />
              ) : isCurrent ? (
                <Check className="size-4 shrink-0 text-primary" aria-hidden="true" />
              ) : null}
            </CommandItem>
          )
        })}
      </CommandList>
    </Command>
  )
}

/** Pasar a Ganado sin monto informa a Meta una venta de $0: se pide antes, dentro del mismo selector. */
function SaleValueStep({ stageName, onBack, onSubmit }: { stageName: string; onBack: () => void; onSubmit: (value?: number) => void }) {
  const { t } = useTranslation()
  const canEditValue = usePermission("opportunities.update")
  const inputId = useId()
  const [amount, setAmount] = useState("")
  const parsed = Number(amount)
  const valid = amount.trim() !== "" && parsed > 0

  return (
    <form
      className="flex flex-col gap-3 p-3"
      onSubmit={(event) => {
        event.preventDefault()
        if (canEditValue && valid) onSubmit(parsed)
      }}
      onKeyDown={(event) => {
        if (event.key === "Escape") {
          // Esc vuelve a la lista de etapas en vez de cerrar todo el selector.
          event.stopPropagation()
          onBack()
        }
      }}
    >
      <div className="flex items-center gap-1">
        <Button type="button" variant="ghost" size="sm" className="size-7 p-0" onClick={onBack} aria-label={t("contactsPage.stage.back")}>
          <ArrowLeft className="size-4" aria-hidden="true" />
        </Button>
        <p className="text-sm font-semibold">{t("pipeline.saleValue.title")}</p>
      </div>
      <p className="text-xs text-muted-foreground">{t("contactsPage.stage.valueHint", { stage: stageName })}</p>
      {canEditValue ? (
        <div className="space-y-1.5">
          <label htmlFor={inputId} className="text-xs font-medium">{t("pipeline.saleValue.label")}</label>
          <Input
            id={inputId}
            type="number"
            inputMode="decimal"
            min={0}
            step="any"
            autoFocus
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            className="tabular-nums"
          />
        </div>
      ) : (
        <p className="text-xs text-muted-foreground">{t("pipeline.saleValue.noPermission")}</p>
      )}
      <div className="flex flex-col gap-1.5">
        {canEditValue && (
          <Button type="submit" size="sm" disabled={!valid}>{t("pipeline.saleValue.save")}</Button>
        )}
        <Button type="button" variant="ghost" size="sm" onClick={() => onSubmit()}>{t("pipeline.saleValue.skip")}</Button>
      </div>
    </form>
  )
}
