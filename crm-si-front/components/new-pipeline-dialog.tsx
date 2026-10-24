"use client"

import { useEffect, useState, type FormEvent } from "react"
import { Copy, LayoutTemplate, Loader2, SquareDashed } from "lucide-react"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { createPipeline, getPipelineStages, type Pipeline } from "@/lib/api/pipeline"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"

type StageSeed = { name: string; color: string }
type StartMode = "copy" | "template" | "empty"

const SIMPLE_TEMPLATE: StageSeed[] = [
  { name: "Nuevo", color: "#3B82F6" },
  { name: "En curso", color: "#F59E0B" },
  { name: "Ganado", color: "#22C55E" },
  { name: "Perdido", color: "#EF4444" },
]

interface NewPipelineDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  pipelines: Pipeline[]
  /** `empty`: se creó sin etapas, conviene abrir el gestor de etapas. */
  onCreated: (pipeline: Pipeline, options: { empty: boolean }) => void
}

/**
 * Alta de pipeline desde el tablero. Además del nombre, resuelve con qué
 * etapas arranca para que el tablero nuevo no quede vacío.
 */
export function NewPipelineDialog({ open, onOpenChange, pipelines, onCreated }: NewPipelineDialogProps) {
  const { t } = useTranslation()
  const defaultSourceId = pipelines.find((item) => item.is_default)?.id ?? pipelines[0]?.id ?? 0
  const [name, setName] = useState("")
  const [mode, setMode] = useState<StartMode>(pipelines.length ? "copy" : "template")
  const [sourceId, setSourceId] = useState(0)
  // Etapas cargadas junto con el pipeline del que vienen: si no coinciden, está cargando.
  const [loadedSource, setLoadedSource] = useState<{ id: number; stages: StageSeed[] } | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState("")

  const effectiveSourceId = sourceId || defaultSourceId

  useEffect(() => {
    if (!open || mode !== "copy" || !effectiveSourceId) return
    let cancelled = false
    getPipelineStages(effectiveSourceId).then((items) => {
      if (cancelled) return
      const stages = [...items].sort((a, b) => a.sort_order - b.sort_order).map(({ name, color }) => ({ name, color }))
      setLoadedSource({ id: effectiveSourceId, stages })
    })
    return () => {
      cancelled = true
    }
  }, [open, mode, effectiveSourceId])

  // Al cerrar se limpia el formulario: la próxima apertura arranca de cero.
  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setName("")
      setError("")
      setSourceId(0)
      setMode(pipelines.length ? "copy" : "template")
    }
    onOpenChange(next)
  }

  const sourceStages = loadedSource?.id === effectiveSourceId ? loadedSource.stages : null
  const preview: StageSeed[] | null = mode === "copy" ? sourceStages : mode === "template" ? SIMPLE_TEMPLATE : []
  const canSubmit = name.trim().length > 0 && !saving && preview !== null

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!canSubmit) return
    setSaving(true)
    setError("")
    try {
      const pipeline = await createPipeline(name.trim(), preview ?? [])
      handleOpenChange(false)
      onCreated(pipeline, { empty: (preview ?? []).length === 0 })
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t("pipeline.create.error"))
    } finally {
      setSaving(false)
    }
  }

  const options: { value: StartMode; icon: typeof Copy; title: string; hint: string; disabled?: boolean }[] = [
    { value: "copy", icon: Copy, title: t("pipeline.create.copyTitle"), hint: t("pipeline.create.copyHint"), disabled: pipelines.length === 0 },
    { value: "template", icon: LayoutTemplate, title: t("pipeline.create.templateTitle"), hint: t("pipeline.create.templateHint") },
    { value: "empty", icon: SquareDashed, title: t("pipeline.create.emptyTitle"), hint: t("pipeline.create.emptyHint") },
  ]

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <form onSubmit={submit} className="space-y-5">
          <DialogHeader>
            <DialogTitle>{t("pipeline.create.title")}</DialogTitle>
            <DialogDescription>{t("pipeline.create.description")}</DialogDescription>
          </DialogHeader>

          <label className="block space-y-1.5 text-sm">
            <span className="font-medium">{t("pipeline.create.nameLabel")}</span>
            <Input
              autoFocus
              value={name}
              maxLength={255}
              onChange={(event) => setName(event.target.value)}
              placeholder={t("pipeline.create.namePlaceholder")}
            />
          </label>

          <fieldset className="space-y-2">
            <legend className="mb-2 text-sm font-medium">{t("pipeline.create.stagesLabel")}</legend>
            <div className="grid gap-2 sm:grid-cols-3" role="radiogroup">
              {options.map(({ value, icon: Icon, title, hint, disabled }) => {
                const active = mode === value
                return (
                  <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={active}
                    disabled={disabled}
                    onClick={() => setMode(value)}
                    className={cn(
                      "flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors disabled:pointer-events-none disabled:opacity-40",
                      active ? "border-primary bg-primary/5" : "border-border hover:border-foreground/30 hover:bg-muted/40",
                    )}
                  >
                    <Icon className={cn("h-4 w-4", active ? "text-primary" : "text-muted-foreground")} aria-hidden="true" />
                    <span className="text-sm font-medium">{title}</span>
                    <span className="text-xs leading-snug text-muted-foreground">{hint}</span>
                  </button>
                )
              })}
            </div>

            {mode === "copy" && pipelines.length > 1 && (
              <Select value={String(effectiveSourceId)} onValueChange={(value) => setSourceId(Number(value))}>
                <SelectTrigger className="h-9" aria-label={t("pipeline.create.copyFrom")}>
                  <span className="text-muted-foreground">{t("pipeline.create.copyFrom")}</span>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {pipelines.map((pipeline) => (
                    <SelectItem key={pipeline.id} value={String(pipeline.id)}>{pipeline.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </fieldset>

          {/* Vista previa: así van a quedar las columnas del tablero. */}
          <div className="rounded-lg border border-dashed border-border bg-muted/20 p-3" aria-live="polite">
            <p className="mb-2 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{t("pipeline.create.preview")}</p>
            {preview === null ? (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" aria-hidden="true" />
            ) : preview.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("pipeline.create.emptyPreview")}</p>
            ) : (
              <ol className="flex flex-wrap gap-1.5">
                {preview.map((stage, index) => (
                  <li
                    key={`${stage.name}-${index}`}
                    className="flex items-center gap-1.5 rounded-md border border-border bg-background px-2 py-1 text-xs animate-in fade-in-0 slide-in-from-left-1"
                    style={{ animationDelay: `${index * 30}ms`, animationFillMode: "both" }}
                  >
                    <span aria-hidden="true" className="size-2 rounded-full" style={{ backgroundColor: stage.color }} />
                    {stage.name}
                  </li>
                ))}
              </ol>
            )}
          </div>

          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>{t("common.cancel")}</Button>
            <Button type="submit" disabled={!canSubmit}>
              {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />}
              {t("pipeline.create.submit")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
