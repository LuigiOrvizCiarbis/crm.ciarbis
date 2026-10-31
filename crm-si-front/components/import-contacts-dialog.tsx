"use client"

import { useEffect, useMemo, useState, useRef, useCallback } from "react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Progress } from "@/components/ui/progress"
import { Upload, FileText, CheckCircle2, AlertCircle, Info, Loader2, X } from "lucide-react"
import { getAuthToken, workspaceHeaders } from "@/lib/api/auth-token"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"
import { readImportFile } from "@/lib/import/import-file"

interface ImportContactsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onImportComplete: () => void
}

interface ImportResult {
  imported: number
  duplicates: number
  errors: number
  error_rows: Array<{ row: number; reason: string }>
  total: number
}

type Step = "upload" | "mapping" | "importing" | "queued" | "results"
type ImportRun = { id: number; status: string; error?: string; result?: ImportResult }

// Priority-ordered patterns per field: first match wins, each field assigned at most once
const FIELD_PATTERNS: Array<{ field: string; priority: number; pattern: RegExp }> = [
  { field: "name", priority: 1, pattern: /^(full[\s_]?name|nombre\s*completo)$/ },
  { field: "name", priority: 2, pattern: /^(name|nombre)$/ },
  { field: "name", priority: 3, pattern: /^(first[\s_]?name|primer[\s_]?nombre)$/ },
  { field: "phone", priority: 1, pattern: /^(phone|tel[eé]fono|celular|mobile|whatsapp|phone[\s_]?number)$/ },
  { field: "phone", priority: 2, pattern: /^tel$/ },
  { field: "email", priority: 1, pattern: /^(email|e-mail|correo|correo\s*electr[oó]nico|mail)$/ },
]


function autoDetectMapping(headers: string[]): string[] {
  // Collect all candidate matches: { field, columnIndex, priority }
  const candidates: Array<{ field: string; col: number; priority: number }> = []

  headers.forEach((header, colIndex) => {
    const h = header.toLowerCase().trim()
    for (const { field, priority, pattern } of FIELD_PATTERNS) {
      if (pattern.test(h)) {
        candidates.push({ field, col: colIndex, priority })
        break // one match per column
      }
    }
  })

  // For each field, pick the best (lowest priority number) candidate
  const assigned = new Map<string, number>()
  candidates
    .sort((a, b) => a.priority - b.priority)
    .forEach(({ field, col }) => {
      if (!assigned.has(field)) {
        assigned.set(field, col)
      }
    })

  // Build the mapping array via reverse lookup
  const colToField = new Map<number, string>()
  for (const [field, col] of assigned) colToField.set(col, field)

  return headers.map((_, i) => colToField.get(i) ?? "ignore")
}

export function ImportContactsDialog({ open, onOpenChange, onImportComplete }: ImportContactsDialogProps) {
  const contactFields = useContactFieldsStore((s) => s.fields)
  const fetchContactFields = useContactFieldsStore((s) => s.fetch)
  const fieldsLoaded = useContactFieldsStore((s) => s.loaded)

  useEffect(() => {
    if (open && !fieldsLoaded) fetchContactFields()
  }, [open, fieldsLoaded, fetchContactFields])

  const fieldOptions = useMemo(
    () => [
      { value: "ignore", label: "Ignorar" },
      { value: "name", label: "Nombre" },
      { value: "phone", label: "Teléfono" },
      { value: "email", label: "Email" },
      ...contactFields.map((f) => ({ value: `custom.${f.key}`, label: f.label })),
    ],
    [contactFields],
  )

  const [step, setStep] = useState<Step>("upload")
  const [file, setFile] = useState<File | null>(null)
  const [headers, setHeaders] = useState<string[]>([])
  const [previewRows, setPreviewRows] = useState<string[][]>([])
  const [columnMapping, setColumnMapping] = useState<string[]>([])
  const [result, setResult] = useState<ImportResult | null>(null)
  const [run, setRun] = useState<ImportRun | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [dragOver, setDragOver] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const reset = useCallback(() => {
    setStep("upload")
    setFile(null)
    setHeaders([])
    setPreviewRows([])
    setColumnMapping([])
    setResult(null)
    setRun(null)
    setError(null)
    setDragOver(false)
  }, [])

  const handleOpenChange = (open: boolean) => {
    if (!open) reset()
    onOpenChange(open)
  }

  const processFile = async (f: File) => {
    setError(null)
    try {
      const parsed = await readImportFile(f)
      if (parsed.rows.length === 0) throw new Error("El archivo debe tener al menos una fila de datos")
      setFile(parsed.file)
      setHeaders(parsed.headers)
      setPreviewRows(parsed.rows.slice(0, 5))
      setColumnMapping(autoDetectMapping(parsed.headers))
      setStep("mapping")
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No se pudo leer el archivo")
    }
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setDragOver(false)
    const f = e.dataTransfer.files[0]
    if (f) void processFile(f)
  }

  const handleFileInput = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]
    if (f) void processFile(f)
  }

  const handleImport = async () => {
    if (!file) return

    // Build mapping object: { name, phone, email, custom: { fieldKey: colIndex, ... } }
    const mapping: Record<string, unknown> = {}
    const customMapping: Record<string, number> = {}
    columnMapping.forEach((field, index) => {
      if (field === "ignore") return
      if (field.startsWith("custom.")) {
        customMapping[field.slice("custom.".length)] = index
      } else {
        mapping[field] = index
      }
    })
    if (Object.keys(customMapping).length > 0) {
      mapping.custom = customMapping
    }

    if (!("name" in mapping)) {
      setError("Debes mapear al menos la columna de Nombre")
      return
    }

    setError(null)
    setStep("importing")

    try {
      const token = getAuthToken()
      const formData = new FormData()
      formData.append("file", file)
      formData.append("mapping", JSON.stringify(mapping))

      const response = await fetch("/api/contacts/import/queue", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() },
        body: formData,
      })

      const json = await response.json()

      if (!response.ok) {
        const msg = json.message || json.errors?.file?.[0] || json.errors?.mapping?.[0] || "Error al importar"
        setError(msg)
        setStep("mapping")
        return
      }

      setRun(json.data)
      setStep("queued")
    } catch {
      setError("Error de conexión al importar")
      setStep("mapping")
    }
  }

  useEffect(() => {
    if (step !== "queued" || !run) return
    const timer = window.setInterval(async () => {
      const response = await fetch(`/api/contacts/import/${run.id}`, { headers: { Authorization: `Bearer ${getAuthToken()}`, ...workspaceHeaders() } })
      if (!response.ok) return
      const json = await response.json()
      setRun(json.data)
      if (["completed", "failed", "cancelled"].includes(json.data.status)) {
        if (json.data.result) setResult(json.data.result)
        setStep("results")
        if (json.data.status === "completed") onImportComplete()
      }
    }, 1500)
    return () => window.clearInterval(timer)
  }, [step, run?.id, onImportComplete])

  const handleClose = () => {
    if (result && result.imported > 0) {
      onImportComplete()
    }
    handleOpenChange(false)
  }

  const nameIsMapped = columnMapping.includes("name")

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-4xl min-w-0">
        <DialogHeader>
          <DialogTitle>Importar Contactos</DialogTitle>
          <DialogDescription>
            {step === "upload" && "Selecciona un archivo CSV para importar contactos"}
            {step === "mapping" && "Mapea las columnas del archivo a los campos de contacto"}
            {step === "importing" && "Preparando importación..."}
            {step === "queued" && "Importando contactos en segundo plano..."}
            {step === "results" && "Resultados de la importación"}
          </DialogDescription>
        </DialogHeader>

        {/* Step 1: Upload */}
        {step === "upload" && (
          <div className="py-4">
            <div
              className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors cursor-pointer ${
                dragOver ? "border-primary bg-primary/5" : "border-muted-foreground/25 hover:border-primary/50"
              }`}
              onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
              onDragLeave={() => setDragOver(false)}
              onDrop={handleDrop}
              onClick={() => fileInputRef.current?.click()}
            >
              <Upload className="w-10 h-10 text-muted-foreground mx-auto mb-3" />
              <p className="text-sm font-medium mb-1">
                Arrastra tu archivo CSV aquí o haz clic para seleccionar
              </p>
              <p className="text-xs text-muted-foreground">
                Formatos aceptados: .csv o .xlsx (máximo 10 MB)
              </p>
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv,.xlsx"
                className="hidden"
                onChange={handleFileInput}
              />
            </div>

            {error && (
              <p className="text-sm text-destructive mt-3 flex items-center gap-1">
                <AlertCircle className="w-4 h-4 shrink-0" />
                {error}
              </p>
            )}
          </div>
        )}

        {step === "queued" && (
          <div className="py-10 text-center">
            <Loader2 className="w-7 h-7 animate-spin text-primary mx-auto mb-3" />
            <p className="text-sm text-muted-foreground">La importación continúa aunque cierres esta ventana.</p>
          </div>
        )}

        {/* Step 2: Mapping */}
        {step === "mapping" && (
          <div className="py-2 space-y-4 min-w-0">
            {file && (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <FileText className="w-4 h-4 shrink-0" />
                <span className="truncate">{file.name}</span>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-6 w-6 p-0 ml-auto shrink-0"
                  onClick={() => { reset(); setStep("upload") }}
                >
                  <X className="w-3 h-3" />
                </Button>
              </div>
            )}

            <div className="border rounded-lg overflow-x-auto min-w-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    {headers.map((header, i) => (
                      <TableHead key={i} className="min-w-35">
                        <div className="space-y-1.5">
                          <p className="text-xs text-muted-foreground truncate">{header}</p>
                          <Select
                            value={columnMapping[i] || "ignore"}
                            onValueChange={(value) => {
                              setColumnMapping(prev => {
                                const next = [...prev]
                                // Clear the same field from any other column
                                if (value !== "ignore") {
                                  const existing = next.indexOf(value)
                                  if (existing !== -1) next[existing] = "ignore"
                                }
                                next[i] = value
                                return next
                              })
                            }}
                          >
                            <SelectTrigger className="h-7 text-xs">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {fieldOptions.map((opt) => (
                                <SelectItem key={opt.value} value={opt.value} className="text-xs">
                                  {opt.label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {previewRows.map((row, ri) => (
                    <TableRow key={ri}>
                      {headers.map((_, ci) => (
                        <TableCell key={ci} className="text-xs truncate max-w-50">
                          {row[ci] || ""}
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {previewRows.length > 0 && (
              <p className="text-xs text-muted-foreground">
                Mostrando {previewRows.length} de las primeras filas como vista previa
              </p>
            )}

            {error && (
              <p className="text-sm text-destructive flex items-center gap-1">
                <AlertCircle className="w-4 h-4 shrink-0" />
                {error}
              </p>
            )}
          </div>
        )}

        {/* Step 3: Importing */}
        {step === "importing" && (
          <div className="py-8 space-y-4">
            <div className="flex items-center justify-center gap-2">
              <Loader2 className="w-5 h-5 animate-spin text-primary" />
              <p className="text-sm font-medium">Importando contactos...</p>
            </div>
            <Progress value={undefined} className="animate-pulse" />
            <p className="text-xs text-muted-foreground text-center">
              Esto puede tomar unos segundos dependiendo del tamaño del archivo
            </p>
          </div>
        )}

        {/* Step 4: Results */}
        {step === "results" && result && (
          <div className="py-4 space-y-4">
            <div className="grid grid-cols-3 gap-3">
              <div className="rounded-lg border p-3 text-center">
                <CheckCircle2 className="w-5 h-5 text-green-600 mx-auto mb-1" />
                <p className="text-2xl font-bold text-green-600">{result.imported}</p>
                <p className="text-xs text-muted-foreground">Importados</p>
              </div>
              <div className="rounded-lg border p-3 text-center">
                <Info className="w-5 h-5 text-yellow-600 mx-auto mb-1" />
                <p className="text-2xl font-bold text-yellow-600">{result.duplicates}</p>
                <p className="text-xs text-muted-foreground">Duplicados</p>
              </div>
              <div className="rounded-lg border p-3 text-center">
                <AlertCircle className="w-5 h-5 text-red-600 mx-auto mb-1" />
                <p className="text-2xl font-bold text-red-600">{result.errors}</p>
                <p className="text-xs text-muted-foreground">Errores</p>
              </div>
            </div>

            {result.error_rows.length > 0 && (
              <div className="space-y-2">
                <p className="text-sm font-medium">Detalle de errores</p>
                <div className="border rounded-lg max-h-40 overflow-y-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-20">Fila</TableHead>
                        <TableHead>Razón</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {result.error_rows.map((err, i) => (
                        <TableRow key={i}>
                          <TableCell className="text-xs">{err.row}</TableCell>
                          <TableCell className="text-xs">{err.reason}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {step === "upload" && (
            <Button variant="outline" onClick={() => handleOpenChange(false)}>
              Cancelar
            </Button>
          )}
          {step === "mapping" && (
            <>
              <Button variant="outline" onClick={() => { reset(); setStep("upload") }}>
                Volver
              </Button>
              <Button onClick={handleImport} disabled={!nameIsMapped}>
                Importar
              </Button>
            </>
          )}
          {step === "results" && (
            <Button onClick={handleClose}>
              Cerrar
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
