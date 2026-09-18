"use client"

import { useEffect, useMemo, useRef, useState } from "react"
import * as XLSX from "xlsx"
import { AlertCircle, CheckCircle2, Loader2, PlusCircle, Upload } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { getAuthToken } from "@/lib/api/auth-token"
import type { ProductField } from "@/lib/api/product-fields"

type Step = "upload" | "mapping" | "review" | "queued" | "results"
type Field = { id: string; label: string; type: string; options?: { choices: string[] } }
type Run = { id: number; status: string; error?: string; result?: { created: number; updated: number; duplicates: number; errors: number; error_rows: { row: number; reason: string }[] } }
interface Props { open: boolean; onOpenChange: (value: boolean) => void; onImportComplete: () => void; productFields?: ProductField[] }

const norm = (v: string) => v.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim().replace(/[ _-]/g, "")
const infer = (values: string[]) => {
  const sample = values.filter(Boolean).slice(0, 200)
  if (!sample.length) return "text"
  if (sample.every((v) => /^(true|false|si|sí|no|yes|1|0)$/i.test(v))) return "boolean"
  if (sample.every((v) => /^\S+@\S+\.\S+$/.test(v))) return "email"
  if (sample.every((v) => /^https?:\/\//i.test(v))) return "url"
  if (sample.every((v) => /^\d{4}-\d{2}-\d{2}$|^\d{1,2}[/-]\d{1,2}[/-]\d{2,4}$/.test(v))) return "date"
  if (sample.every((v) => /^[-+]?[$€]?\s?\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?$/.test(v))) return "number"
  return "text"
}
const native = [{ value: "ignore", label: "Ignorar" }, { value: "name", label: "Nombre" }, { value: "price", label: "Precio" }, { value: "description", label: "Descripción" }, { value: "is_active", label: "Activo" }]

export function ImportProductsDialog({ open, onOpenChange, onImportComplete, productFields = [] }: Props) {
  const [step, setStep] = useState<Step>("upload"), [file, setFile] = useState<File | null>(null), [originalName, setOriginalName] = useState("")
  const [book, setBook] = useState<XLSX.WorkBook | null>(null), [sheet, setSheet] = useState("")
  const [headers, setHeaders] = useState<string[]>([]), [rows, setRows] = useState<string[][]>([]), [mapping, setMapping] = useState<string[]>([])
  const [fields, setFields] = useState<Field[]>([]), [mode, setMode] = useState("create"), [matchField, setMatchField] = useState("name"), [preserveEmpty, setPreserveEmpty] = useState(true)
  const [preview, setPreview] = useState<{ total_rows: number; duplicate_rows: number[]; warnings: string[] } | null>(null), [run, setRun] = useState<Run | null>(null)
  const [error, setError] = useState(""), [busy, setBusy] = useState(false)
  const input = useRef<HTMLInputElement>(null)
  const reset = () => { setStep("upload"); setFile(null); setOriginalName(""); setBook(null); setSheet(""); setHeaders([]); setRows([]); setMapping([]); setFields([]); setPreview(null); setRun(null); setError("") }
  const close = () => { if (run?.status === "completed") onImportComplete(); reset(); onOpenChange(false) }
  const applyCsv = (csv: string, name: string) => {
    const parsed = XLSX.utils.sheet_to_json<string[]>(XLSX.read(csv, { type: "string" }).Sheets.Sheet1 || XLSX.utils.aoa_to_sheet([]), { header: 1, defval: "" }).map((r) => r.map(String))
    if (!parsed.length) { setError("El archivo no contiene filas válidas"); return }
    const headers = parsed[0]; setHeaders(headers); setRows(parsed.slice(1)); setOriginalName(name)
    setMapping(headers.map((header) => {
      const h = norm(header)
      if (/^(name|nombre|producto|product|titulo|title)$/.test(h)) return "name"
      if (/^(price|precio|importe|valor|amount|costo)$/.test(h)) return "price"
      if (/^(description|descripcion|detalle)$/.test(h)) return "description"
      if (/^(activo|active|estado|status|habilitado)$/.test(h)) return "is_active"
      return "ignore"
    }))
    setFile(new File([csv], name.replace(/\.[^.]+$/, "") + ".csv", { type: "text/csv" })); setStep("mapping")
  }
  const chooseSheet = (workbook: XLSX.WorkBook, name: string, original: string) => { setSheet(name); setBook(workbook); applyCsv(XLSX.utils.sheet_to_csv(workbook.Sheets[name]), original); setSheet(name) }
  const readFile = async (selected: File) => {
    setError("")
    if (selected.size > 10 * 1024 * 1024) { setError("El archivo no puede superar los 10 MB"); return }
    if (/\.xlsx$/i.test(selected.name)) { const workbook = XLSX.read(await selected.arrayBuffer(), { type: "array" }); if (!workbook.SheetNames.length) { setError("El libro no contiene hojas"); return }; chooseSheet(workbook, workbook.SheetNames[0], selected.name); return }
    if (!/\.csv$/i.test(selected.name)) { setError("Solo se aceptan archivos CSV o XLSX"); return }
    applyCsv(await selected.text(), selected.name)
  }
  const targets = useMemo(() => [...native, ...productFields.map((f) => ({ value: "custom:" + f.key, label: f.label }))], [productFields])
  const updateTarget = (index: number, value: string) => {
    const next = [...mapping], id = "column-" + index
    if (value === "create") {
      if (!fields.some((field) => field.id === id)) setFields([...fields, { id, label: headers[index] || "Campo " + (index + 1), type: infer(rows.map((row) => row[index] || "")) }])
      next[index] = "proposed:" + id
    } else { setFields(fields.filter((field) => field.id !== id)); next[index] = value }
    setMapping(next)
  }
  const updateField = (id: string, patch: Partial<Field>) => setFields(fields.map((field) => field.id === id ? { ...field, ...patch } : field))
  const preparedFields = () => fields.map((field) => {
    if (field.type !== "select") return field
    const column = Number(field.id.replace("column-", ""))
    const choices = Array.from(new Set(rows.map((row) => (row[column] || "").trim()).filter(Boolean))).slice(0, 50)
    return { ...field, options: { choices } }
  })
  const payload = (proposedFields: Field[]) => {
    const output: Record<string, unknown> = { has_headers: true, proposed_fields: proposedFields }, custom: Record<string, number> = {}
    mapping.forEach((target, index) => { if (target.indexOf("custom:") === 0) custom[target.slice(7)] = index; else if (target.indexOf("proposed:") === 0) custom[target] = index; else if (target !== "ignore") output[target] = index })
    if (Object.keys(custom).length) output.custom = custom
    return output
  }
  const send = async (action: "preview" | "queue") => {
    if (!file) return
    if (!mapping.includes("name")) { setError("Debes mapear Nombre para crear productos nuevos"); return }
    const proposedFields = preparedFields()
    if (proposedFields.some((field) => field.type === "select" && !(field.options?.choices.length))) {
      setError("Un campo de selección necesita al menos una opción distinta en su columna")
      return
    }
    setBusy(true); setError("")
    const form = new FormData(); form.append("file", file); form.append("mapping", JSON.stringify(payload(proposedFields))); form.append("mode", mode); form.append("match_field", matchField); form.append("preserve_empty", String(preserveEmpty)); form.append("original_filename", originalName); if (sheet) form.append("sheet_name", sheet)
    try {
      const response = await fetch("/api/products/import/" + action, { method: "POST", headers: { Authorization: "Bearer " + getAuthToken() }, body: form }), json = await response.json()
      if (!response.ok) throw new Error(json.message || (json.errors?.mapping?.[0]) || "No se pudo procesar la importación")
      if (action === "preview") { setPreview(json.data); setStep("review") } else { setRun(json.data); setStep("queued") }
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Error de conexión") } finally { setBusy(false) }
  }
  useEffect(() => {
    if (step !== "queued" || !run) return
    const timer = window.setInterval(async () => { const response = await fetch("/api/products/import/" + run.id, { headers: { Authorization: "Bearer " + getAuthToken() } }); if (!response.ok) return; const json = await response.json(); setRun(json.data); if (["completed", "failed", "cancelled"].includes(json.data.status)) { setStep("results"); if (json.data.status === "completed") onImportComplete() } }, 1500)
    return () => window.clearInterval(timer)
  }, [step, run?.id, onImportComplete])

  return <Dialog open={open} onOpenChange={(value) => value ? onOpenChange(true) : close()}><DialogContent className="max-w-6xl"><DialogHeader><DialogTitle>Importar catálogo</DialogTitle><DialogDescription>{step === "upload" ? "CSV o Excel; creá los campos que falten sin salir del importador." : step === "mapping" ? "Mapeá columnas y revisá los campos sugeridos." : step === "review" ? "Confirmá el resumen antes de encolar la importación." : step === "queued" ? "La importación se ejecuta en segundo plano." : "Resultado de la importación."}</DialogDescription></DialogHeader>
    {step === "upload" && <div className="py-8"><button type="button" className="w-full rounded-xl border-2 border-dashed border-primary/30 bg-primary/5 p-12 text-center hover:border-primary" onClick={() => input.current?.click()}><Upload className="mx-auto mb-3 h-9 w-9 text-primary" /><span className="block font-medium">Elegí un archivo</span><span className="text-sm text-muted-foreground">CSV o XLSX · hasta 10 MB · TXT no admitido</span></button><input ref={input} className="hidden" type="file" accept=".csv,.xlsx" onChange={(e) => { const selected = e.target.files?.[0]; if (selected) void readFile(selected); e.target.value = "" }} /></div>}
    {step === "mapping" && <div className="space-y-3 py-2">{book && book.SheetNames.length > 1 && <div className="flex items-center gap-2 text-sm">Hoja <Select value={sheet} onValueChange={(name) => chooseSheet(book, name, originalName)}><SelectTrigger className="w-48"><SelectValue /></SelectTrigger><SelectContent>{book.SheetNames.map((name) => <SelectItem key={name} value={name}>{name}</SelectItem>)}</SelectContent></Select></div>}<div className="flex flex-wrap items-center gap-3 rounded-lg border bg-muted/30 p-3 text-sm"><span>Modo</span><Select value={mode} onValueChange={setMode}><SelectTrigger className="w-48"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="create">Crear solamente</SelectItem><SelectItem value="update">Actualizar solamente</SelectItem><SelectItem value="upsert">Crear y actualizar</SelectItem></SelectContent></Select>{mode !== "create" && <><span>Identificar por</span><Select value={matchField} onValueChange={setMatchField}><SelectTrigger className="w-48"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="name">Nombre</SelectItem>{productFields.filter((f) => f.is_unique).map((f) => <SelectItem key={f.key} value={"custom:" + f.key}>{f.label}</SelectItem>)}</SelectContent></Select><label><input type="checkbox" checked={preserveEmpty} onChange={(e) => setPreserveEmpty(e.target.checked)} /> Conservar vacíos</label></>}</div><div className="max-h-100 overflow-auto rounded-lg border"><Table><TableHeader><TableRow><TableHead>Columna</TableHead><TableHead>Destino</TableHead><TableHead>Campo nuevo</TableHead><TableHead>Ejemplos</TableHead></TableRow></TableHeader><TableBody>{headers.map((header, index) => { const field = fields.find((item) => item.id === "column-" + index); return <TableRow key={index}><TableCell>{header || <span className="text-destructive">Sin encabezado</span>}</TableCell><TableCell><Select value={mapping[index] || "ignore"} onValueChange={(value) => updateTarget(index, value)}><SelectTrigger className="w-52"><SelectValue /></SelectTrigger><SelectContent>{targets.map((target) => <SelectItem key={target.value} value={target.value}>{target.label}</SelectItem>)}<SelectItem value="create"><span className="flex items-center gap-1 text-primary"><PlusCircle className="h-3.5 w-3.5" />Crear campo</span></SelectItem></SelectContent></Select></TableCell><TableCell>{field && <div className="flex gap-2"><input className="h-8 w-32 rounded border bg-background px-2 text-sm" value={field.label} onChange={(e) => updateField(field.id, { label: e.target.value })} /><Select value={field.type} onValueChange={(type) => updateField(field.id, { type })}><SelectTrigger className="h-8 w-26"><SelectValue /></SelectTrigger><SelectContent>{["text", "number", "date", "boolean", "email", "url", "select"].map((type) => <SelectItem key={type} value={type}>{type}</SelectItem>)}</SelectContent></Select></div>}</TableCell><TableCell className="max-w-52 text-xs text-muted-foreground">{rows.slice(0, 3).map((row) => row[index]).filter(Boolean).join(" · ")}</TableCell></TableRow> })}</TableBody></Table></div></div>}
    {step === "review" && preview && <div className="grid grid-cols-3 gap-3 py-5"><div className="rounded-lg border p-4"><b className="text-2xl">{preview.total_rows}</b><p className="text-sm text-muted-foreground">filas detectadas</p></div><div className="rounded-lg border p-4"><b className="text-2xl">{fields.length}</b><p className="text-sm text-muted-foreground">campos a crear</p></div><div className="rounded-lg border p-4"><b className="text-2xl text-amber-600">{preview.duplicate_rows.length}</b><p className="text-sm text-muted-foreground">duplicados en archivo</p></div></div>}
    {step === "queued" && <div className="py-12 text-center"><Loader2 className="mx-auto mb-3 h-7 w-7 animate-spin text-primary" /><p>{run?.status === "queued" ? "Esperando worker…" : "Importando productos…"}</p></div>}
    {step === "results" && <div className="space-y-4 py-4">{run?.status === "completed" ? <div className="grid grid-cols-4 gap-3">{[["Creados", run.result?.created], ["Actualizados", run.result?.updated], ["Omitidos", run.result?.duplicates], ["Errores", run.result?.errors]].map(([label, value]) => <div key={String(label)} className="rounded-lg border p-3 text-center"><b className="text-2xl">{value || 0}</b><p className="text-xs text-muted-foreground">{label}</p></div>)}</div> : <p className="text-destructive">{run?.error || "La importación fue cancelada."}</p>}</div>}
    {error && <p className="flex items-center gap-2 text-sm text-destructive"><AlertCircle className="h-4 w-4" />{error}</p>}<DialogFooter>{step === "upload" && <Button variant="outline" onClick={close}>Cancelar</Button>}{step === "mapping" && <><Button variant="outline" onClick={reset}>Cambiar archivo</Button><Button disabled={busy} onClick={() => void send("preview")}>{busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Revisar</Button></>}{step === "review" && <><Button variant="outline" onClick={() => setStep("mapping")}>Volver</Button><Button disabled={busy} onClick={() => void send("queue")}>Confirmar e importar</Button></>}{step === "queued" && run?.status === "queued" && <Button variant="outline" onClick={() => fetch("/api/products/import/" + run.id + "/cancel", { method: "POST", headers: { Authorization: "Bearer " + getAuthToken() } })}>Cancelar</Button>}{step === "results" && <Button onClick={close}><CheckCircle2 className="mr-2 h-4 w-4" />Cerrar</Button>}</DialogFooter>
  </DialogContent></Dialog>
}
