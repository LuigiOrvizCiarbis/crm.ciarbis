"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, FileImage, Loader2, Plus, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import { createTemplate, uploadTemplateHeader } from "@/lib/api/templates"

type HeaderFormat = "NONE" | "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT"
type ButtonDraft = { type: "QUICK_REPLY" | "URL" | "PHONE_NUMBER"; text: string; url?: string; phone_number?: string }

interface NewTemplateDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  channelId: number
  onCreated: () => Promise<void>
}

export function NewTemplateDialog({ open, onOpenChange, channelId, onCreated }: NewTemplateDialogProps) {
  const { addToast } = useToast()
  const [name, setName] = useState("")
  const [language, setLanguage] = useState("es_AR")
  const [category, setCategory] = useState<"MARKETING" | "UTILITY">("UTILITY")
  const [headerFormat, setHeaderFormat] = useState<HeaderFormat>("NONE")
  const [headerText, setHeaderText] = useState("")
  const [headerFile, setHeaderFile] = useState<File | null>(null)
  const [body, setBody] = useState("")
  const [footer, setFooter] = useState("")
  const [examples, setExamples] = useState<Record<string, string>>({})
  const [buttons, setButtons] = useState<ButtonDraft[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [confirmDiscard, setConfirmDiscard] = useState(false)
  const variables = useMemo(() => [...new Set(Array.from(body.matchAll(/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/g)).map((match) => match[1]))], [body])
  const hasDraft = Boolean(name || body.trim() || footer.trim() || headerFile || headerText.trim() || buttons.length > 0)

  useEffect(() => setExamples((current) => Object.fromEntries(variables.map((variable) => [variable, current[variable] || ""]))), [variables])

  const reset = () => {
    setName(""); setLanguage("es_AR"); setCategory("UTILITY")
    setHeaderFormat("NONE"); setHeaderText(""); setHeaderFile(null)
    setBody(""); setFooter(""); setExamples({}); setButtons([])
    setConfirmDiscard(false)
  }

  const requestClose = () => {
    if (hasDraft && !submitting) { setConfirmDiscard(true); return }
    reset()
    onOpenChange(false)
  }

  const submit = async () => {
    if (!name.match(/^[a-z0-9_]+$/)) return addToast({ type: "error", title: "El nombre solo puede usar minúsculas, números y guiones bajos." })
    if (!body.trim()) return addToast({ type: "error", title: "El cuerpo de la plantilla es obligatorio." })
    if (variables.some((variable) => !examples[variable]?.trim())) return addToast({ type: "error", title: "Agregá un ejemplo para cada variable." })
    if (headerFormat !== "NONE" && headerFormat !== "TEXT" && !headerFile) return addToast({ type: "error", title: "Seleccioná el archivo de ejemplo del encabezado." })
    setSubmitting(true)
    try {
      let headerHandle: string | undefined
      if (headerFile) headerHandle = (await uploadTemplateHeader(channelId, headerFile)).header_handle
      const components: any[] = []
      if (headerFormat === "TEXT") components.push({ type: "HEADER", format: "TEXT", text: headerText.trim() })
      if (headerHandle) components.push({ type: "HEADER", format: headerFormat, example: { header_handle: [headerHandle] } })
      components.push({ type: "BODY", text: body.trim(), ...(variables.length ? { example: { body_text_named_params: variables.map((param_name) => ({ param_name, example: examples[param_name].trim() })) } } : {}) })
      if (footer.trim()) components.push({ type: "FOOTER", text: footer.trim() })
      if (buttons.length) components.push({ type: "BUTTONS", buttons: buttons.map(({ type, text, url, phone_number }) => ({ type, text, ...(url ? { url } : {}), ...(phone_number ? { phone_number } : {}) })) })
      await createTemplate(channelId, { name: name.trim(), language, category, parameter_format: "named", components })
      addToast({ type: "success", title: "Plantilla enviada a revisión de Meta." })
      reset()
      onOpenChange(false)
      await onCreated()
    } catch (error: any) {
      addToast({ type: "error", title: error?.message || "No se pudo crear la plantilla" })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) requestClose(); else onOpenChange(true) }}>
      <DialogContent className="flex max-h-[92vh] max-w-4xl flex-col overflow-hidden p-0 sm:max-w-4xl">
        {confirmDiscard ? (
          <div className="flex flex-col gap-4 p-6">
            <DialogHeader className="p-0">
              <DialogTitle>¿Descartar esta plantilla?</DialogTitle>
              <DialogDescription>Perdés el contenido cargado. No se envió nada a revisión todavía.</DialogDescription>
            </DialogHeader>
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setConfirmDiscard(false)}>Seguir editando</Button>
              <Button variant="destructive" size="sm" onClick={() => { reset(); onOpenChange(false) }}>Descartar</Button>
            </div>
          </div>
        ) : (
          <>
            <DialogHeader className="border-b border-border px-6 py-4">
              <DialogTitle>Nueva plantilla</DialogTitle>
              <DialogDescription>Se enviará a revisión de Meta antes de poder usarse en una difusión.</DialogDescription>
            </DialogHeader>

            <div className="grid flex-1 gap-6 overflow-y-auto px-6 py-6 lg:grid-cols-[1.3fr_0.7fr]">
              <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="block space-y-1.5 text-sm font-medium">
                    Nombre
                    <Input value={name} onChange={(event) => setName(event.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_"))} placeholder="confirmacion_pedido" />
                  </label>
                  <label className="block space-y-1.5 text-sm font-medium">
                    Idioma
                    <select value={language} onChange={(event) => setLanguage(event.target.value)} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                      <option value="es_AR">Español (Argentina)</option>
                      <option value="es_MX">Español (México)</option>
                      <option value="en_US">English (US)</option>
                      <option value="pt_BR">Português (Brasil)</option>
                    </select>
                  </label>
                </div>

                <label className="block space-y-1.5 text-sm font-medium">
                  Categoría
                  <select value={category} onChange={(event) => setCategory(event.target.value as "MARKETING" | "UTILITY")} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                    <option value="UTILITY">Utilidad</option>
                    <option value="MARKETING">Marketing</option>
                  </select>
                </label>

                <div className="space-y-3 rounded-lg border border-border p-4">
                  <p className="text-sm font-medium">Encabezado <span className="font-normal text-muted-foreground">(opcional)</span></p>
                  <select value={headerFormat} onChange={(event) => { setHeaderFormat(event.target.value as HeaderFormat); setHeaderFile(null) }} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                    <option value="NONE">Sin encabezado</option>
                    <option value="TEXT">Texto</option>
                    <option value="IMAGE">Imagen</option>
                    <option value="VIDEO">Video</option>
                    <option value="DOCUMENT">Documento PDF</option>
                  </select>
                  {headerFormat === "TEXT" && (
                    <Input value={headerText} onChange={(event) => setHeaderText(event.target.value)} placeholder="Actualización de tu pedido" />
                  )}
                  {headerFormat !== "NONE" && headerFormat !== "TEXT" && (
                    <label className="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground hover:bg-muted/40">
                      <FileImage className="size-4" />
                      <span className="truncate">{headerFile?.name || "Elegir archivo de ejemplo"}</span>
                      <input
                        type="file"
                        className="sr-only"
                        accept={headerFormat === "IMAGE" ? "image/jpeg,image/png" : headerFormat === "VIDEO" ? "video/mp4" : "application/pdf"}
                        onChange={(event) => setHeaderFile(event.target.files?.[0] || null)}
                      />
                    </label>
                  )}
                </div>

                <label className="block space-y-1.5 text-sm font-medium">
                  Cuerpo
                  <Textarea value={body} onChange={(event) => setBody(event.target.value)} rows={5} placeholder="Hola {{nombre}}, tu pedido {{numero_pedido}} está listo." />
                  <span className="block text-xs font-normal text-muted-foreground">Usá variables nombradas como &#123;&#123;nombre&#125;&#125;.</span>
                </label>

                {variables.length > 0 && (
                  <div className="space-y-3 rounded-lg border border-border bg-muted/20 p-4">
                    <p className="text-sm font-medium">Ejemplos de variables</p>
                    {variables.map((variable) => (
                      <label key={variable} className="flex items-center gap-3 text-sm">
                        <code className="min-w-28 rounded bg-background px-2 py-1 text-xs">&#123;&#123;{variable}&#125;&#125;</code>
                        <Input value={examples[variable] || ""} onChange={(event) => setExamples((current) => ({ ...current, [variable]: event.target.value }))} placeholder="Ejemplo para revisión" />
                      </label>
                    ))}
                  </div>
                )}

                <label className="block space-y-1.5 text-sm font-medium">
                  Pie <span className="font-normal text-muted-foreground">(opcional)</span>
                  <Input value={footer} onChange={(event) => setFooter(event.target.value)} maxLength={60} placeholder="Respondé AYUDA si necesitás asistencia" />
                </label>

                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium">Botones <span className="font-normal text-muted-foreground">(opcional, máximo 3)</span></p>
                    {buttons.length < 3 && (
                      <Button type="button" size="sm" variant="outline" onClick={() => setButtons((current) => [...current, { type: "QUICK_REPLY", text: "" }])}>
                        <Plus className="mr-1 size-3" /> Agregar
                      </Button>
                    )}
                  </div>
                  {buttons.map((button, index) => (
                    <div key={index} className="grid gap-2 rounded-lg border border-border p-3 sm:grid-cols-[140px_1fr_auto]">
                      <select
                        value={button.type}
                        onChange={(event) => setButtons((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, type: event.target.value as ButtonDraft["type"] } : item))}
                        className="h-10 rounded-md border border-input bg-background px-2 text-sm"
                      >
                        <option value="QUICK_REPLY">Respuesta rápida</option>
                        <option value="URL">URL</option>
                        <option value="PHONE_NUMBER">Teléfono</option>
                      </select>
                      <Input value={button.text} onChange={(event) => setButtons((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, text: event.target.value } : item))} placeholder="Texto del botón" />
                      {button.type === "URL" && (
                        <Input value={button.url || ""} onChange={(event) => setButtons((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, url: event.target.value } : item))} placeholder="https://…" />
                      )}
                      {button.type === "PHONE_NUMBER" && (
                        <Input value={button.phone_number || ""} onChange={(event) => setButtons((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, phone_number: event.target.value } : item))} placeholder="54911…" />
                      )}
                      <Button type="button" variant="ghost" size="icon" onClick={() => setButtons((current) => current.filter((_, itemIndex) => itemIndex !== index))}>
                        <X className="size-4" />
                      </Button>
                    </div>
                  ))}
                </div>
              </div>

              <div className="rounded-lg border border-border bg-muted/30 p-4">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Vista previa</p>
                <div className="rounded-lg rounded-tl-sm border border-border bg-card p-3 text-sm text-card-foreground shadow-xs dark:shadow-none">
                  {(headerFormat === "TEXT" ? headerText : headerFormat !== "NONE" ? headerFormat : "") && (
                    <p className="mb-2 text-[11px] font-medium text-muted-foreground">
                      {headerFormat === "TEXT" ? headerText || "Encabezado" : headerFormat}
                    </p>
                  )}
                  <p className="whitespace-pre-wrap">{body || "El cuerpo de tu plantilla aparecerá acá."}</p>
                  {footer && <p className="mt-2 text-xs text-muted-foreground">{footer}</p>}
                  {buttons.length > 0 && (
                    <div className="mt-3 space-y-1 border-t border-border pt-2">
                      {buttons.map((button, index) => (
                        <p key={index} className="text-center text-xs font-medium text-primary">{button.text || "Botón"}</p>
                      ))}
                    </div>
                  )}
                </div>
                <p className="mt-4 text-xs leading-5 text-muted-foreground">Meta revisará el contenido antes de habilitarlo. El estado inicial aparecerá como pendiente.</p>
              </div>
            </div>

            <DialogFooter className="border-t border-border px-6 py-4">
              <Button variant="outline" onClick={requestClose} disabled={submitting}>Cancelar</Button>
              <Button onClick={submit} disabled={submitting}>
                {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Check className="mr-2 size-4" />} Enviar a revisión
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
