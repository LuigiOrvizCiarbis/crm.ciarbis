"use client"

import { useId, useState } from "react"
import { Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { usePipelineCatalog } from "@/hooks/usePipelineCatalog"
import { useTranslation } from "@/hooks/useTranslation"
import { bulkUpdateContactStage } from "@/lib/api/contacts"
import { StageMark } from "./ContactStagePicker"

interface BulkStageDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  selectedIds: number[]
  onSuccess: (failedIds: number[]) => void
}

/**
 * Lleva a los contactos seleccionados a una etapa. Cada contacto mueve su
 * oportunidad en ese embudo o, si no tiene, se agrega con una nueva.
 */
export function BulkStageDialog({ open, onOpenChange, selectedIds, onSuccess }: BulkStageDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canEditValue = usePermission("opportunities.update")
  const { catalog, failed, retry } = usePipelineCatalog(open)
  const amountId = useId()
  const [pipelineId, setPipelineId] = useState<number | null>(null)
  const [stageId, setStageId] = useState<number | null>(null)
  const [amount, setAmount] = useState("")
  const [submitting, setSubmitting] = useState(false)

  const entry = catalog?.find((item) => item.pipeline.id === pipelineId)
    ?? catalog?.find((item) => item.pipeline.is_default)
    ?? catalog?.[0]
  const stage = entry?.stages.find((item) => item.id === stageId) ?? null
  const parsedAmount = Number(amount)
  const amountEntered = amount.trim() !== ""
  const amountValid = !amountEntered || parsedAmount > 0
  const askValue = stage?.outcome === "won" && canEditValue

  const handleApply = async () => {
    if (!stage || !amountValid || selectedIds.length === 0 || submitting) return
    setSubmitting(true)
    try {
      const result = await bulkUpdateContactStage({
        ids: selectedIds,
        pipeline_stage_id: stage.id,
        ...(askValue && amountEntered ? { value: parsedAmount } : {}),
      })
      if (result.failed > 0) {
        addToast({
          type: "info",
          title: t("contactsPage.bulk.stage.result.partial", { updated: result.updated, failed: result.failed }),
          description: result.failures.slice(0, 3).map((failure) => failure.reason).join(" "),
        })
      } else {
        addToast({ type: "success", title: t("contactsPage.bulk.stage.result.success", { updated: result.updated, stage: stage.name }) })
      }
      onOpenChange(false)
      onSuccess(result.failures.map((failure) => failure.id))
    } catch (error) {
      addToast({
        type: "error",
        title: t("contactsPage.bulk.stage.errors.apply"),
        description: error instanceof Error ? error.message : "",
      })
    } finally {
      setSubmitting(false)
    }
  }

  const renderBody = () => {
    if (failed) {
      return (
        <div className="flex flex-col items-center gap-3 py-6 text-center text-sm text-muted-foreground">
          <p>{t("chats.stagesLoadError")}</p>
          <Button variant="outline" size="sm" onClick={retry}>{t("chats.retry")}</Button>
        </div>
      )
    }

    if (!catalog) {
      return (
        <div role="status" aria-label={t("chats.stageFilter.loading")} className="space-y-2 py-1">
          {[0, 1, 2, 3].map((row) => <Skeleton key={row} className="h-9 w-full" />)}
        </div>
      )
    }

    if (!entry) {
      return <p className="py-6 text-center text-sm text-muted-foreground">{t("contactsPage.stage.noPipelines")}</p>
    }

    return (
      <div className="space-y-4">
        {catalog.length > 1 && (
          <div className="space-y-1.5">
            <Label>{t("contactsPage.bulk.stage.pipeline")}</Label>
            <Select
              value={String(entry.pipeline.id)}
              onValueChange={(value) => {
                setPipelineId(Number(value))
                setStageId(null)
              }}
              disabled={submitting}
            >
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {catalog.map(({ pipeline }) => (
                  <SelectItem key={pipeline.id} value={String(pipeline.id)}>{pipeline.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        <div className="space-y-1.5">
          <Label id="bulk-stage-label">{t("contactsPage.bulk.stage.stage")}</Label>
          {entry.stages.length === 0 ? (
            <p className="py-4 text-center text-sm text-muted-foreground">{t("contactsPage.stage.noStages")}</p>
          ) : (
            <ScrollArea className="max-h-[min(40vh,20rem)] pr-3">
              <RadioGroup
                aria-labelledby="bulk-stage-label"
                value={stageId !== null ? String(stageId) : ""}
                onValueChange={(value) => setStageId(Number(value))}
                disabled={submitting}
                className="gap-1"
              >
                {entry.stages.map((item) => (
                  <label
                    key={item.id}
                    htmlFor={`bulk-stage-${item.id}`}
                    className="flex cursor-pointer items-center gap-2.5 rounded-md border border-transparent px-2 py-2 text-sm transition-colors hover:bg-muted has-[[data-state=checked]]:border-border has-[[data-state=checked]]:bg-muted/60"
                  >
                    <RadioGroupItem id={`bulk-stage-${item.id}`} value={String(item.id)} />
                    <StageMark stage={item} />
                    <span className="min-w-0 flex-1 truncate">{item.name}</span>
                  </label>
                ))}
              </RadioGroup>
            </ScrollArea>
          )}
        </div>

        {askValue && (
          <div className="space-y-1.5">
            <Label htmlFor={amountId}>{t("pipeline.saleValue.label")}</Label>
            <Input
              id={amountId}
              type="number"
              inputMode="decimal"
              min={0}
              step="any"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              aria-invalid={!amountValid}
              disabled={submitting}
              className="tabular-nums"
            />
            <p className="text-xs text-muted-foreground">{t("contactsPage.bulk.stage.valueHint", { stage: stage.name })}</p>
          </div>
        )}
      </div>
    )
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !submitting && onOpenChange(next)}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("contactsPage.bulk.stage.title")}</DialogTitle>
          <DialogDescription>{t("contactsPage.bulk.stage.subtitle", { count: selectedIds.length })}</DialogDescription>
        </DialogHeader>

        <p className="text-sm text-muted-foreground">{t("contactsPage.bulk.stage.hint")}</p>
        {renderBody()}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>{t("contactsPage.bulk.dialog.cancel")}</Button>
          <Button onClick={() => void handleApply()} disabled={submitting || !stage || !amountValid}>
            {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
            {t("contactsPage.bulk.stage.apply")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
