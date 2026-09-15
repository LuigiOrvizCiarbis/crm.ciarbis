"use client"

import { useCallback, useMemo, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Progress } from "@/components/ui/progress"
import { Upload, FileText, CheckCircle2, AlertCircle, Info, Loader2, X } from "lucide-react"
import { getAuthToken } from "@/lib/api/auth-token"
import type { ProductField } from "@/lib/api/product-fields"

interface ImportProductsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onImportComplete: () => void
  productFields?: ProductField[]
}

interface ImportResult {
  imported: number
  duplicates: number
  errors: number
  error_rows: Array<{ row: number; reason: string }>
  total: number
}

type Step = "upload" | "mapping" | "importing" | "results"

const FIELD_OPTIONS = [
  { value: "ignore", label: "Ignorar" },
  { value: "name", label: "Nombre" },
  { value: "price", label: "Precio" },
  { value: "description", label: "Descripción" },
  { value: "is_active", label: "Activo" },
]

// Priority-ordered patterns per field: first match wins, each field assigned at most once
const FIELD_PATTERNS: Array<{ field: string; priority: number; pattern: RegExp }> = [
  { field: "name", priority: 1, pattern: /^(name|nombre|producto|product|t[ií]tulo|title)$/ },
  { field: "price", priority: 1, pattern: /^(price|precio|importe|valor|amount|cost[eo]?)$/ },
  { field: "description", priority: 1, pattern: /^(description|descripci[oó]n|detalle|detail)$/ },
  { field: "is_active", priority: 1, pattern: /^(is[\s_]?active|activo|active|estado|status|habilitado|enabled)$/ },
]

const CSV_FILE_REGEX = /\.(csv|txt)$/i

function autoDetectMapping(headers: string[]): string[] {
  const candidates: Array<{ field: string; col: number; priority: number }> = []

  headers.forEach((header, colIndex) => {
    const h = header.toLowerCase().trim()
    for (const { field, priority, pattern } of FIELD_PATTERNS) {
      if (pattern.test(h)) {
        candidates.push({ field, col: colIndex, priority })
        break
      }
    }
  })

  const assigned = new Map<string, number>()
  candidates
    .sort((a, b) => a.priority - b.priority)
    .forEach(({ field, col }) => {
      if (!assigned.has(field)) assigned.set(field, col)
    })

  const colToField = new Map<number, string>()
  for (const [field, col] of assigned) colToField.set(col, field)

  return headers.map((_, i) => colToField.get(i) ?? "ignore")
}

function detectDelimiter(firstLine: string): string {
  const semicolons = (firstLine.match(/;/g) || []).length
  const commas = (firstLine.match(/,/g) || []).length
  return semicolons > commas ? ";" : ","
}

function parseCSV(text: string): string[][] {
  const firstLine = text.split(/\r?\n/)[0] || ""
  const delimiter = detectDelimiter(firstLine)

  const rows: string[][] = []
  let current = ""
  let inQuotes = false
  let row: string[] = []

  for (let i = 0; i < text.length; i++) {
    const char = text[i]
    const next = text[i + 1]

    if (inQuotes) {
      if (char === '"' && next === '"') {
        current += '"'
        i++
      } else if (char === '"') {
        inQuotes = false
      } else {
        current += char
      }
    } else {
      if (char === '"') {
        inQuotes = true
      } else if (char === delimiter) {
        row.push(current)
        current = ""
      } else if (char === "\n" || (char === "\r" && next === "\n")) {
        row.push(current)
        current = ""
        if (row.some((cell) => cell.trim() !== "")) rows.push(row)
        row = []
        if (char === "\r") i++
      } else {
        current += char
      }
    }
  }

  if (current !== "" || row.length > 0) {
    row.push(current)
    if (row.some((cell) => cell.trim() !== "")) rows.push(row)
  }

  return rows
}

export function ImportProductsDialog({ open, onOpenChange, onImportComplete, productFields = [] }: ImportProductsDialogProps) {
  const [step, setStep] = useState<Step>("upload")
  const [file, setFile] = useState<File | null>(null)
  const [headers, setHeaders] = useState<string[]>([])
  const [previewRows, setPreviewRows] = useState<string[][]>([])
  const [columnMapping, setColumnMapping] = useState<string[]>([])
  const [result, setResult] = useState<ImportResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [dragOver, setDragOver] = useState(false)
  const [hasHeaders, setHasHeaders] = useState(true)
  const [parsedRows, setParsedRows] = useState<string[][]>([])
  const fileInputRef = useRef<HTMLInputElement>(null)

  const reset = useCallback(() => {
    setStep("upload")
    setFile(null)
    setHeaders([])
    setPreviewRows([])
    setColumnMapping([])
    setResult(null)
    setError(null)
    setDragOver(false)
    setHasHeaders(true)
    setParsedRows([])
  }, [])

  const handleOpenChange = (open: boolean) => {
    if (!open) reset()
    onOpenChange(open)
  }

  const processFile = (f: File) => {
    if (!CSV_FILE_REGEX.test(f.name)) {
      setError("Solo se permiten archivos CSV (.csv o .txt)")
      return
    }

    setError(null)
    setFile(f)

    const reader = new FileReader()
    reader.onload = (e) => {
      const text = e.target?.result as string
      if (!text) {
        setError("No se pudo leer el archivo")
        return
      }

      const rows = parseCSV(text)

      if (rows.length < 1) {
        setError("El archivo no contiene filas válidas")
        return
      }

      setParsedRows(rows)
      const hdrs = rows[0]
      setHeaders(hdrs)
      setPreviewRows(rows.slice(1, 6))
      setColumnMapping(autoDetectMapping(hdrs))
      setHasHeaders(true)
      setStep("mapping")
    }
    reader.onerror = () => setError("Error al leer el archivo")
    reader.readAsText(f, "UTF-8")
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setDragOver(false)
    const f = e.dataTransfer.files[0]
    if (f) processFile(f)
  }

  const handleFileInput = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]
    if (f) processFile(f)
    e.target.value = ""
  }

  const handleImport = async () => {
    if (!file) return

    const mapping: Record<string, unknown> = { has_headers: hasHeaders }
    const custom: Record<string, number> = {}
    columnMapping.forEach((field, index) => {
      if (field === "ignore") return
      if (field.startsWith("custom:")) custom[field.slice(7)] = index
      else mapping[field] = index
    })
    if (Object.keys(custom).length) mapping.custom = custom

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

      const response = await fetch("/api/products/import", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}` },
        body: formData,
      })

      const json = await response.json()

      if (!response.ok) {
        const msg = json.message || json.errors?.file?.[0] || json.errors?.mapping?.[0] || "Error al importar"
        setError(msg)
        setStep("mapping")
        return
      }

      setResult(json.data)
      setStep("results")
    } catch {
      setError("Error de conexión al importar")
      setStep("mapping")
    }
  }

  const handleClose = () => {
    if (result && result.imported > 0) {
      onImportComplete()
    }
    handleOpenChange(false)
  }

  const nameIsMapped = columnMapping.includes("name")
  const fieldOptions = useMemo(
    () => [
      ...FIELD_OPTIONS,
      ...productFields
        .filter((f) => !["name", "price", "description", "is_active"].includes(f.key))
        .map((f) => ({ value: `custom:${f.key}`, label: f.label })),
    ],
    [productFields],
  )

  const changeHeaderMode = (value: boolean) => {
    setHasHeaders(value)
    if (!parsedRows.length) return
    const hdrs = value ? parsedRows[0] : parsedRows[0].map((_, i) => `Columna ${i + 1}`)
    setHeaders(hdrs)
    setPreviewRows(value ? parsedRows.slice(1, 6) : parsedRows.slice(0, 5))
    setColumnMapping(value ? autoDetectMapping(hdrs) : hdrs.map(() => "ignore"))
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-4xl min-w-0">
        <DialogHeader>
          <DialogTitle>Importar Productos</DialogTitle>
          <DialogDescription>
            {step === "upload" && "Selecciona un archivo CSV para importar productos"}
            {step === "mapping" && "Mapea las columnas del archivo a los campos del producto"}
            {step === "importing" && "Importando productos..."}
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
                Formatos aceptados: .csv, .txt (máximo 5 MB)
              </p>
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv,.txt"
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

        {/* Step 2: Mapping */}
        {step === "mapping" && (
          <div className="py-2 space-y-4 min-w-0">
            <div className="rounded-lg border bg-muted/30 p-3 space-y-2">
              <p className="text-sm font-medium">¿La primera fila contiene encabezados?</p>
              <div className="flex gap-4 text-sm">
                <label className="flex items-center gap-2"><input type="radio" checked={hasHeaders === true} onChange={() => changeHeaderMode(true)} /> Sí</label>
                <label className="flex items-center gap-2"><input type="radio" checked={hasHeaders === false} onChange={() => changeHeaderMode(false)} /> No, son datos</label>
              </div>
            </div>
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
              <p className="text-sm font-medium">Importando productos...</p>
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
