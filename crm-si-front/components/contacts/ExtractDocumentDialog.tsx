"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { AlertCircle, FileText, Info, Loader2, Upload } from "lucide-react"
import { CustomFieldInput } from "@/components/CustomFieldInput"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"
import type { ContactField } from "@/lib/api/contact-fields"
import {
  confirmExtraction,
  extractionErrorMessage,
  getExtraction,
  StaleContactError,
  startExtraction,
  uploadContactDocument,
  type DocumentExtraction,
} from "@/lib/api/document-extractions"

interface ExtractDocumentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  contactId: number
  /** custom_data actual, para saber qué campos se estarían pisando. */
  currentCustomData: Record<string, unknown>
  onConfirmed: (customData: Record<string, unknown>) => void
}

type Step = "upload" | "extracting" | "review"

const POLL_INTERVAL_MS = 3000
/** Tope de espera: si el worker murió sin dejar rastro, no colgar la UI para siempre. */
const POLL_TIMEOUT_MS = 5 * 60 * 1000

export function ExtractDocumentDialog({
  open,
  onOpenChange,
  contactId,
  currentCustomData,
  onConfirmed,
}: ExtractDocumentDialogProps) {
  const { fields, fetch: fetchFields } = useContactFieldsStore()

  const [step, setStep] = useState<Step>("upload")
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [extraction, setExtraction] = useState<DocumentExtraction | null>(null)
  const [values, setValues] = useState<Record<string, unknown>>({})
  const [selected, setSelected] = useState<Record<string, boolean>>({})

  // El polling tiene que cortarse al cerrar el diálogo o cambiar de contacto:
  // si no, sigue pegándole al backend por una extracción que nadie mira.
  const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const cancelled = useRef(false)

  const stopPolling = useCallback(() => {
    cancelled.current = true
    if (pollTimer.current) {
      clearTimeout(pollTimer.current)
      pollTimer.current = null
    }
  }, [])

  useEffect(() => {
    if (!open) {
      stopPolling()
      return
    }
    cancelled.current = false
    void fetchFields()
    return stopPolling
  }, [open, contactId, fetchFields, stopPolling])

  const reset = useCallback(() => {
    stopPolling()
    setStep("upload")
    setError(null)
    setBusy(false)
    setExtraction(null)
    setValues({})
    setSelected({})
  }, [stopPolling])

  const handleOpenChange = (next: boolean) => {
    if (!next) reset()
    onOpenChange(next)
  }

  const poll = useCallback(
    async (extractionId: number, startedAt: number) => {
      if (cancelled.current) return

      try {
        const current = await getExtraction(contactId, extractionId)
        if (cancelled.current) return

        if (current.status === "completed") {
          setExtraction(current)
          const result = current.result ?? {}
          setValues(result)
          // Sólo se pre-tildan los campos con dato: uno vacío no aporta nada.
          setSelected(
            Object.fromEntries(
              Object.entries(result).map(([key, value]) => [key, value !== null && value !== ""]),
            ),
          )
          setStep("review")
          return
        }

        if (current.status === "failed") {
          setExtraction(current)
          setError(extractionErrorMessage(current))
          setStep("upload")
          return
        }

        if (Date.now() - startedAt > POLL_TIMEOUT_MS) {
          setError("La extracción está tardando demasiado. Probá de nuevo en unos minutos.")
          setStep("upload")
          return
        }

        pollTimer.current = setTimeout(() => void poll(extractionId, startedAt), POLL_INTERVAL_MS)
      } catch (err) {
        if (cancelled.current) return
        setError(err instanceof Error ? err.message : "Error al consultar la extracción")
        setStep("upload")
      }
    },
    [contactId],
  )

  const handleFile = async (file: File | undefined) => {
    if (!file) return

    setBusy(true)
    setError(null)

    try {
      const asset = await uploadContactDocument(contactId, file)
      const started = await startExtraction(contactId, asset.id)
      setExtraction(started)
      setStep("extracting")
      void poll(started.id, Date.now())
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error al subir el documento")
    } finally {
      setBusy(false)
    }
  }

  const handleConfirm = async () => {
    if (!extraction) return

    const picked = Object.fromEntries(
      Object.entries(values).filter(([key]) => selected[key]),
    )

    setBusy(true)
    setError(null)

    try {
      const result = await confirmExtraction(
        contactId,
        extraction.id,
        picked,
        extraction.contact_lock_version ?? 0,
      )

      if (result.discarded.length > 0) {
        // Un campo borrado durante la revisión: se avisa en vez de guardarlo
        // en silencio como dato huérfano.
        setError(
          `No se aplicaron ${result.discarded.length} campo(s) porque fueron eliminados: ${result.discarded.join(", ")}.`,
        )
      }

      onConfirmed(result.contact?.custom_data ?? {})
      if (result.discarded.length === 0) handleOpenChange(false)
    } catch (err) {
      if (err instanceof StaleContactError) {
        setError(
          "El contacto fue modificado mientras revisabas. Cerrá y volvé a extraer para no pisar esos cambios.",
        )
      } else {
        setError(err instanceof Error ? err.message : "Error al confirmar los datos")
      }
    } finally {
      setBusy(false)
    }
  }

  const extractableFields = useMemo(
    () => fields.filter((field) => field.type !== "file"),
    [fields],
  )

  const selectedCount = Object.values(selected).filter(Boolean).length

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle>Extraer datos de un documento</DialogTitle>
          <DialogDescription>
            Los datos los sugiere la IA a partir del texto del PDF. Revisalos contra el documento
            antes de guardarlos.
          </DialogDescription>
        </DialogHeader>

        {error && (
          <div className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        {step === "upload" && (
          <div className="space-y-3 py-4">
            <Label htmlFor="extraction-file">Documento PDF</Label>
            <Input
              id="extraction-file"
              type="file"
              accept="application/pdf"
              disabled={busy}
              onChange={(e) => void handleFile(e.target.files?.[0])}
            />
            <p className="text-xs text-muted-foreground">
              El PDF tiene que tener texto seleccionable. Un documento escaneado no se puede leer.
            </p>
            {extractableFields.length === 0 && (
              <p className="text-xs text-destructive">
                No hay campos personalizados configurados. Definilos en Configuración antes de
                extraer datos.
              </p>
            )}
          </div>
        )}

        {step === "extracting" && (
          <div className="flex flex-col items-center gap-3 py-12">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            <p className="text-sm text-muted-foreground">Leyendo el documento…</p>
          </div>
        )}

        {step === "review" && extraction && (
          <ReviewPanels
            extraction={extraction}
            fields={extractableFields}
            values={values}
            selected={selected}
            currentCustomData={currentCustomData}
            onValueChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))}
            onToggle={(key, checked) => setSelected((prev) => ({ ...prev, [key]: checked }))}
          />
        )}

        <DialogFooter className="border-t pt-4">
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
            Cancelar
          </Button>
          {step === "review" && (
            <Button onClick={() => void handleConfirm()} disabled={busy || selectedCount === 0}>
              {busy ? "Guardando…" : `Aplicar ${selectedCount} campo(s)`}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

interface ReviewPanelsProps {
  extraction: DocumentExtraction
  fields: ContactField[]
  values: Record<string, unknown>
  selected: Record<string, boolean>
  currentCustomData: Record<string, unknown>
  onValueChange: (key: string, value: unknown) => void
  onToggle: (key: string, checked: boolean) => void
}

/**
 * Dos paneles: el texto del contrato a la izquierda, los campos a la derecha.
 *
 * La comparación la hace la persona. El sistema no marca ningún valor como
 * verificado: que un dato aparezca en el documento no prueba que corresponda a
 * ese campo, así que un sello de "correcto" sería engañoso.
 */
function ReviewPanels({
  extraction,
  fields,
  values,
  selected,
  currentCustomData,
  onValueChange,
  onToggle,
}: ReviewPanelsProps) {
  const partial = extraction.text_coverage === "partial"
  const missingPages = extraction.pages_without_text ?? []

  return (
    <div className="flex-1 overflow-hidden flex flex-col gap-3">
      {partial && (
        <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
          <Info className="h-4 w-4 shrink-0 mt-0.5 text-amber-600" />
          <span>
            No se pudo leer el texto de {missingPages.length === 1 ? "la página" : "las páginas"}{" "}
            {missingPages.join(", ")}. Un campo vacío puede estar ahí.
          </span>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 flex-1 overflow-hidden">
        <div className="flex flex-col overflow-hidden">
          <div className="flex items-center gap-2 pb-2 text-sm font-medium">
            <FileText className="h-4 w-4" />
            Texto del documento
          </div>
          {/* Texto plano, nunca HTML: el contenido lo controla quien sube el PDF. */}
          <pre className="flex-1 overflow-auto rounded-md border bg-muted/30 p-3 text-xs whitespace-pre-wrap font-mono">
            {extraction.document_text || "Sin texto disponible."}
          </pre>
        </div>

        <div className="flex flex-col overflow-hidden">
          <div className="pb-2 text-sm font-medium">Datos sugeridos</div>
          <div className="flex-1 overflow-auto space-y-3 pr-1">
            {fields.map((field) => {
              const value = values[field.key]
              const isEmpty = value === null || value === undefined || value === ""
              const overwrites =
                selected[field.key] &&
                currentCustomData[field.key] !== undefined &&
                currentCustomData[field.key] !== null &&
                currentCustomData[field.key] !== ""

              return (
                <div key={field.key} className="rounded-md border p-3 space-y-2">
                  <div className="flex items-start gap-2">
                    <Checkbox
                      id={`pick-${field.key}`}
                      checked={!!selected[field.key]}
                      onCheckedChange={(checked) => onToggle(field.key, checked === true)}
                      disabled={isEmpty}
                    />
                    <Label htmlFor={`pick-${field.key}`} className="flex-1 cursor-pointer">
                      {field.label}
                    </Label>
                  </div>

                  {isEmpty ? (
                    <p className="text-xs text-muted-foreground pl-6">
                      {partial
                        ? "Sin dato en el texto que se pudo leer."
                        : "Sin dato en el documento."}
                    </p>
                  ) : (
                    <div className="pl-6">
                      <CustomFieldInput
                        field={field}
                        value={value}
                        onChange={(next) => onValueChange(field.key, next)}
                      />
                    </div>
                  )}

                  {overwrites && (
                    <p className="text-xs text-amber-600 pl-6">
                      Reemplaza el valor actual: {String(currentCustomData[field.key])}
                    </p>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      </div>
    </div>
  )
}
