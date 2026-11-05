"use client"

import { useEffect, useMemo, useState } from "react"
import { ArrowLeft, FileText, Loader2, RefreshCw, Search } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { ScrollArea } from "@/components/ui/scroll-area"
import { useToast } from "@/components/Toast"
import { bulkBroadcastConversations } from "@/lib/api/conversations"
import { getTemplates, syncTemplates, uploadTemplateMedia } from "@/lib/api/templates"
import {
  buildSendComponents,
  buildTemplatePreview,
  defaultParamSource,
  extractBodyParams,
  getHeaderMediaFormat,
  hasUnsupportedParams,
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
import { WhatsAppTemplate } from "@/data/types"
import { cn } from "@/lib/utils"
import { templateCategoryClass } from "@/lib/whatsapp-templates"

interface BroadcastDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  selectedIds: number[]
  channelId: number | null
  onSuccess: () => void
}

export function BroadcastDialog({
  open,
  onOpenChange,
  selectedIds,
  channelId,
  onSuccess,
}: BroadcastDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [templates, setTemplates] = useState<WhatsAppTemplate[]>([])
  const [loading, setLoading] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [selected, setSelected] = useState<WhatsAppTemplate | null>(null)
  const [paramValues, setParamValues] = useState<string[]>([])
  const [paramNames, setParamNames] = useState<string[]>([])
  const [paramSources, setParamSources] = useState<ParamSource[]>([])
  const [mediaFile, setMediaFile] = useState<File | null>(null)
  const [mediaUrl, setMediaUrl] = useState("")
  const [mediaFilename, setMediaFilename] = useState("")
  const [query, setQuery] = useState("")
  const [submitting, setSubmitting] = useState(false)

  const count = selectedIds.length

  useEffect(() => {
    if (!open) return
    setSelected(null)
    setParamValues([])
    setParamNames([])
    setParamSources([])
    setMediaFile(null)
    setMediaUrl("")
    setMediaFilename("")
    setQuery("")
  }, [open])

  useEffect(() => {
    if (!open || channelId === null) return
    let cancelled = false
    setLoading(true)
    getTemplates(channelId)
      .then((loaded) => {
        if (!cancelled) setTemplates(loaded)
      })
      .catch(() => {
        if (!cancelled) {
          addToast({ type: "error", title: t("chats.broadcast.errors.loadTemplates") })
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, channelId])

  const handleSync = async () => {
    if (channelId === null) return
    setSyncing(true)
    try {
      await syncTemplates(channelId)
      setTemplates(await getTemplates(channelId))
    } catch {
      addToast({ type: "error", title: t("chats.broadcast.errors.sync") })
    } finally {
      setSyncing(false)
    }
  }

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return templates
    return templates.filter((tpl) => tpl.name.toLowerCase().includes(q))
  }, [templates, query])

  const handleSelectTemplate = (template: WhatsAppTemplate) => {
    if (hasUnsupportedParams(template.components)) return
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

  const canApply =
    !submitting &&
    count > 0 &&
    selected !== null &&
    (selectedMediaFormat === null || mediaFile !== null || mediaUrl.trim().length > 0) &&
    paramValues.every((v, i) => paramSources[i] !== "custom" || v.trim())

  const handleApply = async () => {
    if (!canApply || !selected || channelId === null) return
    setSubmitting(true)
    try {
      let headerMedia
      if (selectedMediaFormat) {
        if (mediaFile) {
          // Subir el archivo a Meta una sola vez; todos los mensajes de la
          // difusión reutilizan el mismo media id.
          const { media_id } = await uploadTemplateMedia(channelId, mediaFile)
          headerMedia = { id: media_id, filename: mediaFilename.trim() || mediaFile.name }
        } else {
          headerMedia = { link: mediaUrl.trim(), filename: mediaFilename.trim() || undefined }
        }
      }

      const resolvedValues = paramValues.map((v, i) => resolveParamValue(paramSources[i], v))
      const components = buildSendComponents(selected.components, resolvedValues, headerMedia)

      const result = await bulkBroadcastConversations({
        ids: selectedIds,
        template_id: selected.id,
        components,
      })

      if (result.failed > 0) {
        addToast({
          type: "info",
          title: t("chats.broadcast.result.partial", {
            queued: result.queued,
            failed: result.failed,
          }),
        })
      } else {
        addToast({
          type: "success",
          title: t("chats.broadcast.result.success", { queued: result.queued }),
        })
      }

      onOpenChange(false)
      onSuccess()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.broadcast.errors.apply"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg max-h-[85vh] flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            {selected && (
              <Button
                variant="ghost"
                size="sm"
                className="h-6 w-6 p-0"
                onClick={() => setSelected(null)}
                aria-label={t("chats.broadcast.dialog.backToTemplates")}
              >
                <ArrowLeft className="w-4 h-4" aria-hidden="true" />
              </Button>
            )}
            {selected ? selected.name : t("chats.broadcast.dialog.title")}
          </DialogTitle>
          <DialogDescription>
            {t("chats.broadcast.dialog.subtitle", { count })}
          </DialogDescription>
        </DialogHeader>

        {!selected ? (
          /* Lista de plantillas */
          <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="flex items-center gap-2">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder={t("chats.broadcast.dialog.searchTemplates")}
                  className="pl-8"
                />
              </div>
              <Button variant="outline" size="sm" onClick={handleSync} disabled={syncing}>
                <RefreshCw className={cn("h-3 w-3 mr-1", syncing && "animate-spin")} />
                {t("chats.broadcast.dialog.sync")}
              </Button>
            </div>
            <ScrollArea className="max-h-[45vh] flex-1">
              {loading ? (
                <div className="flex items-center gap-2 px-3 py-8 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t("chats.broadcast.dialog.loadingTemplates")}
                </div>
              ) : filtered.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-3 py-8 text-center text-sm text-muted-foreground">
                  <FileText className="h-8 w-8 opacity-50" />
                  {t("chats.broadcast.dialog.noTemplates")}
                </div>
              ) : (
                <div className="flex flex-col gap-2 pr-3">
                  {filtered.map((template) => {
                    const unsupported = hasUnsupportedParams(template.components)
                    const bodyText = template.components.find(
                      (c) => c.type?.toUpperCase() === "BODY",
                    )?.text
                    return (
                      <button
                        key={template.id}
                        type="button"
                        onClick={() => handleSelectTemplate(template)}
                        disabled={unsupported}
                        title={unsupported ? t("chats.broadcast.dialog.unsupportedHint") : undefined}
                        className={cn(
                          "rounded-lg border border-border p-3 text-left transition-colors",
                          unsupported
                            ? "cursor-not-allowed opacity-50"
                            : "hover:bg-accent",
                        )}
                      >
                        <div className="flex items-center justify-between gap-2">
                          <span className="truncate text-sm font-medium">{template.name}</span>
                          <Badge
                            variant="secondary"
                            className={cn("shrink-0 text-xs", templateCategoryClass(template.category))}
                          >
                            {template.category}
                          </Badge>
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                          {template.language}
                          {bodyText && (
                            <span className="ml-2">· {bodyText.substring(0, 60)}…</span>
                          )}
                          {unsupported && (
                            <span className="ml-2 italic">
                              {t("chats.broadcast.dialog.unsupportedHint")}
                            </span>
                          )}
                        </div>
                      </button>
                    )
                  })}
                </div>
              )}
            </ScrollArea>
          </div>
        ) : (
          /* Detalle + parámetros */
          <div className="flex min-h-0 flex-1 flex-col gap-4">
            <div className="flex items-center gap-2">
              <Badge variant="secondary" className={templateCategoryClass(selected.category)}>
                {selected.category}
              </Badge>
              <span className="text-xs text-muted-foreground">{selected.language}</span>
            </div>

            <ScrollArea className="max-h-[40vh] flex-1">
              <div className="space-y-3 pr-3">
                <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-4">
                  <p className="whitespace-pre-wrap text-sm">
                    {buildTemplatePreview(
                      selected.components,
                      paramValues.map((v, i) =>
                        paramSources[i] === "contact_name"
                          ? t("chats.broadcast.dialog.paramSource.contactNamePreview")
                          : paramSources[i] === "contact_phone"
                            ? t("chats.broadcast.dialog.paramSource.contactPhonePreview")
                            : v,
                      ),
                    )}
                  </p>
                </div>

                {selectedMediaFormat && (
                  <div className="space-y-2">
                    <p className="text-sm font-medium">
                      {t(`chats.broadcast.dialog.media.${selectedMediaFormat.toLowerCase()}`)}
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
                        placeholder={t("chats.broadcast.dialog.media.urlPlaceholder")}
                        value={mediaUrl}
                        onChange={(e) => setMediaUrl(e.target.value)}
                      />
                    )}
                    {selectedMediaFormat === "DOCUMENT" && (
                      <Input
                        placeholder={t("chats.broadcast.dialog.media.filenamePlaceholder")}
                        value={mediaFilename}
                        onChange={(e) => setMediaFilename(e.target.value)}
                      />
                    )}
                  </div>
                )}

                {paramValues.length > 0 && (
                  <div className="space-y-3">
                    <p className="text-sm font-medium">{t("chats.broadcast.dialog.params")}</p>
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
                              <SelectItem value="contact_name">
                                {t("chats.broadcast.dialog.paramSource.contactName")}
                              </SelectItem>
                              <SelectItem value="contact_phone">
                                {t("chats.broadcast.dialog.paramSource.contactPhone")}
                              </SelectItem>
                              <SelectItem value="custom">
                                {t("chats.broadcast.dialog.paramSource.custom")}
                              </SelectItem>
                            </SelectContent>
                          </Select>
                          {paramSources[i] === "custom" && (
                            <Input
                              placeholder={t("chats.broadcast.dialog.paramSource.customPlaceholder")}
                              value={val}
                              onChange={(e) => {
                                const value = e.target.value
                                setParamValues((prev) => {
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
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            {t("chats.bulk.dialog.cancel")}
          </Button>
          <Button onClick={handleApply} disabled={!canApply}>
            {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t("chats.broadcast.dialog.send", { count })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
