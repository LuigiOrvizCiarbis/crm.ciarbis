"use client"

import { useState, useEffect } from "react"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { ScrollArea } from "@/components/ui/scroll-area"
import { FileText, RefreshCw, ArrowLeft, Send, Search } from "lucide-react"
import { WhatsAppTemplate } from "@/data/types"
import { getTemplates, syncTemplates, sendTemplate, uploadTemplateMedia } from "@/lib/api/templates"
import {
  buildSendComponents,
  buildTemplatePreview,
  defaultParamSource,
  extractBodyParams,
  getHeaderMediaFormat,
  resolveParamValue,
  type ParamSource,
} from "@/lib/whatsapp-templates"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

interface TemplatePickerProps {
  channelId: number
  conversationId: number
  onSend: (content: string) => void
  disabled?: boolean
  /**
   * Apertura controlada desde afuera. En móvil el picker se abre desde la hoja
   * de acciones del composer, donde no hay lugar para su propio botón.
   */
  open?: boolean
  onOpenChange?: (open: boolean) => void
  /** Oculta el botón propio cuando quien abre el diálogo es otro control. */
  hideTrigger?: boolean
}

const categoryColors: Record<string, string> = {
  MARKETING: "bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200",
  UTILITY: "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200",
  AUTHENTICATION: "bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200",
}

export function TemplatePicker({
  channelId,
  conversationId,
  onSend,
  disabled,
  open: controlledOpen,
  onOpenChange,
  hideTrigger = false,
}: TemplatePickerProps) {
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false)
  const isControlled = controlledOpen !== undefined
  const open = isControlled ? controlledOpen : uncontrolledOpen
  const setOpen = (next: boolean) => {
    if (!isControlled) setUncontrolledOpen(next)
    onOpenChange?.(next)
  }
  const [templates, setTemplates] = useState<WhatsAppTemplate[]>([])
  const [selected, setSelected] = useState<WhatsAppTemplate | null>(null)
  const [paramValues, setParamValues] = useState<string[]>([])
  const [paramNames, setParamNames] = useState<string[]>([])
  const [paramSources, setParamSources] = useState<ParamSource[]>([])
  const [mediaFile, setMediaFile] = useState<File | null>(null)
  const [mediaUrl, setMediaUrl] = useState("")
  const [mediaFilename, setMediaFilename] = useState("")
  const [loading, setLoading] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [sending, setSending] = useState(false)
  const [search, setSearch] = useState("")
  const [error, setError] = useState<string | null>(null)

  const fetchTemplates = async () => {
    setLoading(true)
    setError(null)
    try {
      const data = await getTemplates(channelId)
      setTemplates(data)
    } catch (e: any) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (open && channelId) {
      fetchTemplates()
      setSelected(null)
      setSearch("")
    }
  }, [open, channelId])

  const handleSync = async () => {
    setSyncing(true)
    try {
      await syncTemplates(channelId)
      await fetchTemplates()
    } catch (e: any) {
      setError(e.message)
    } finally {
      setSyncing(false)
    }
  }

  const handleSelectTemplate = (template: WhatsAppTemplate) => {
    setSelected(template)
    const { names } = extractBodyParams(template.components)
    setParamNames(names)
    setParamValues(new Array(names.length).fill(""))
    setParamSources(names.map(defaultParamSource))
    setMediaFile(null)
    setMediaUrl("")
    setMediaFilename("")
  }

  const selectedMediaFormat = selected ? getHeaderMediaFormat(selected.components) : null

  // Valores para el preview: los orígenes de contacto se muestran como etiqueta
  // (el valor real se resuelve por conversación en el backend).
  const displayParamValues = () =>
    paramValues.map((v, i) =>
      paramSources[i] === "contact_name"
        ? "«nombre del contacto»"
        : paramSources[i] === "contact_phone"
          ? "«teléfono del contacto»"
          : v,
    )

  const handleSend = async () => {
    if (!selected) return
    setSending(true)
    try {
      let headerMedia
      if (selectedMediaFormat) {
        if (mediaFile) {
          const { media_id } = await uploadTemplateMedia(channelId, mediaFile)
          headerMedia = { id: media_id, filename: mediaFilename.trim() || mediaFile.name }
        } else {
          headerMedia = { link: mediaUrl.trim(), filename: mediaFilename.trim() || undefined }
        }
      }

      const resolvedValues = paramValues.map((v, i) => resolveParamValue(paramSources[i], v))
      const components = buildSendComponents(selected.components, resolvedValues, headerMedia)

      // Crear mensaje optimista ANTES de enviar al backend
      const preview = buildTemplatePreview(selected.components, displayParamValues())
      const summary = preview
        ? `📋 ${selected.name}\n${preview}`
        : `📋 ${selected.name}`
      onSend(summary)
      setOpen(false)

      await sendTemplate(conversationId, selected.id, components)
    } catch (e: any) {
      setError(e.message)
    } finally {
      setSending(false)
    }
  }

  const filtered = templates.filter((t) =>
    t.name.toLowerCase().includes(search.toLowerCase())
  )

  const header = selected?.components.find((c) => c.type === "HEADER" || c.type === "header")
  const body = selected?.components.find((c) => c.type === "BODY" || c.type === "body")
  const footer = selected?.components.find((c) => c.type === "FOOTER" || c.type === "footer")
  const buttons = selected?.components.find((c) => c.type === "BUTTONS" || c.type === "buttons")

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {!hideTrigger && (
        <DialogTrigger asChild>
          <Button variant="ghost" size="sm" disabled={disabled} title="Plantillas de WhatsApp">
            <FileText className="w-4 h-4" />
          </Button>
        </DialogTrigger>
      )}
      <DialogContent className="sm:max-w-lg max-h-[80vh] flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            {selected && (
              <Button
                variant="ghost"
                size="sm"
                className="h-6 w-6 p-0"
                onClick={() => setSelected(null)}
              >
                <ArrowLeft className="w-4 h-4" />
              </Button>
            )}
            {selected ? selected.name : "Plantillas de WhatsApp"}
            {!selected && (
              <Button
                variant="outline"
                size="sm"
                className="ml-auto"
                onClick={handleSync}
                disabled={syncing}
              >
                <RefreshCw className={`w-3 h-3 mr-1 ${syncing ? "animate-spin" : ""}`} />
                Sincronizar
              </Button>
            )}
          </DialogTitle>
        </DialogHeader>

        {error && (
          <div className="text-sm text-destructive bg-destructive/10 rounded-md px-3 py-2">
            {error}
          </div>
        )}

        {!selected ? (
          /* List view */
          <div className="flex flex-col gap-3 min-h-0 flex-1">
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Buscar plantilla..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
              />
            </div>
            <ScrollArea className="flex-1 max-h-[50vh]">
              {loading ? (
                <div className="flex items-center justify-center py-8 text-muted-foreground text-sm">
                  Cargando plantillas...
                </div>
              ) : filtered.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-8 text-muted-foreground text-sm gap-2">
                  <FileText className="w-8 h-8 opacity-50" />
                  {templates.length === 0
                    ? "No hay plantillas. Haz clic en Sincronizar."
                    : "No se encontraron resultados"}
                </div>
              ) : (
                <div className="flex flex-col gap-2 pr-3">
                  {filtered.map((template) => (
                    <button
                      key={template.id}
                      onClick={() => handleSelectTemplate(template)}
                      className="text-left p-3 rounded-lg border border-border hover:bg-accent transition-colors"
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-medium text-sm truncate">{template.name}</span>
                        <Badge variant="secondary" className={`text-xs shrink-0 ${categoryColors[template.category] || ""}`}>
                          {template.category}
                        </Badge>
                      </div>
                      <div className="text-xs text-muted-foreground mt-1">
                        {template.language}
                        {template.components.find((c) => c.type === "BODY" || c.type === "body")?.text && (
                          <span className="ml-2 truncate">
                            — {template.components.find((c) => c.type === "BODY" || c.type === "body")!.text!.substring(0, 60)}...
                          </span>
                        )}
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </ScrollArea>
          </div>
        ) : (
          /* Detail view */
          <div className="flex flex-col gap-4 min-h-0 flex-1">
            <div className="flex items-center gap-2">
              <Badge variant="secondary" className={categoryColors[selected.category] || ""}>
                {selected.category}
              </Badge>
              <span className="text-xs text-muted-foreground">{selected.language}</span>
            </div>

            <ScrollArea className="flex-1 max-h-[40vh]">
              <div className="space-y-3 pr-3">
                {/* Template preview */}
                <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-2">
                  {header?.text && (
                    <p className="font-semibold text-sm">{header.text}</p>
                  )}
                  {body?.text && (
                    <p className="text-sm whitespace-pre-wrap">
                      {buildTemplatePreview(selected.components, displayParamValues())}
                    </p>
                  )}
                  {footer?.text && (
                    <p className="text-xs text-muted-foreground">{footer.text}</p>
                  )}
                  {buttons?.buttons && buttons.buttons.length > 0 && (
                    <div className="flex flex-wrap gap-2 pt-2 border-t border-border">
                      {buttons.buttons.map((btn, i) => (
                        <span key={i} className="text-xs px-2 py-1 rounded border border-border bg-background">
                          {btn.text}
                        </span>
                      ))}
                    </div>
                  )}
                </div>

                {/* Media header (documento/imagen/video) */}
                {selectedMediaFormat && (
                  <div className="space-y-2">
                    <p className="text-sm font-medium">
                      {selectedMediaFormat === "DOCUMENT" ? "Documento (PDF)" : selectedMediaFormat === "IMAGE" ? "Imagen" : "Video"}
                    </p>
                    <Input
                      type="file"
                      accept={
                        selectedMediaFormat === "DOCUMENT"
                          ? "application/pdf"
                          : selectedMediaFormat === "IMAGE"
                            ? "image/jpeg,image/png"
                            : "video/mp4"
                      }
                      onChange={(e) => setMediaFile(e.target.files?.[0] ?? null)}
                    />
                    {!mediaFile && (
                      <Input
                        placeholder="o pegá una URL pública del archivo (https://...)"
                        value={mediaUrl}
                        onChange={(e) => setMediaUrl(e.target.value)}
                      />
                    )}
                    {selectedMediaFormat === "DOCUMENT" && (
                      <Input
                        placeholder="Nombre del archivo (opcional, ej. comprobante.pdf)"
                        value={mediaFilename}
                        onChange={(e) => setMediaFilename(e.target.value)}
                      />
                    )}
                  </div>
                )}

                {/* Parameter inputs */}
                {paramValues.length > 0 && (
                  <div className="space-y-3">
                    <p className="text-sm font-medium">Parámetros</p>
                    {paramValues.map((val, i) => (
                      <div key={i} className="space-y-1.5">
                        <p className="text-xs text-muted-foreground">{`{{${paramNames[i] ?? i + 1}}}`}</p>
                        <div className="flex gap-2">
                          <Select
                            value={paramSources[i]}
                            onValueChange={(source) => {
                              setParamSources((prev) => {
                                const next = [...prev]
                                next[i] = source as ParamSource
                                return next
                              })
                            }}
                          >
                            <SelectTrigger className="w-44 shrink-0">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="contact_name">Nombre del contacto</SelectItem>
                              <SelectItem value="contact_phone">Teléfono del contacto</SelectItem>
                              <SelectItem value="custom">Texto fijo</SelectItem>
                            </SelectContent>
                          </Select>
                          {paramSources[i] === "custom" && (
                            <Input
                              placeholder="Escribí el valor..."
                              value={val}
                              onChange={(e) => {
                                const value = e.target.value
                                setParamValues(prev => {
                                  const next = [...prev]
                                  next[i] = value
                                  return next
                                })
                              }}
                            />
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </ScrollArea>

            <Button
              onClick={handleSend}
              disabled={
                sending ||
                (selectedMediaFormat !== null && !mediaFile && !mediaUrl.trim()) ||
                paramValues.some((v, i) => paramSources[i] === "custom" && !v.trim())
              }
              className="w-full"
            >
              <Send className="w-4 h-4 mr-2" />
              {sending ? "Enviando..." : "Enviar plantilla"}
            </Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
