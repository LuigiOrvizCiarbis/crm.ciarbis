"use client"

import { useEffect, useState } from "react"
import { FileText, Image as ImageIcon, Video } from "lucide-react"

import type { WhatsAppTemplate } from "@/data/types"
import { useImagePreviewUrl } from "@/hooks/useImagePreviewUrl"
import { useTranslation } from "@/hooks/useTranslation"
import { getHeaderMediaFormat, templateHeaderExampleUrl } from "@/lib/whatsapp-templates"

interface TemplateHeaderPreviewProps {
  template: WhatsAppTemplate
  /** Archivo elegido para este envío; reemplaza al de la plantilla. */
  file: File | null
  /** URL válida pegada para este envío (ya normalizada); reemplaza al de la plantilla. */
  url: string | null
}

/**
 * Encabezado de media en la vista previa de un envío: el archivo elegido para
 * este envío o, si no hay, el guardado con la plantilla. Una plantilla creada
 * fuera del CRM muestra el ejemplo que devuelve Meta al sincronizar.
 */
export function TemplateHeaderPreview({ template, file, url }: TemplateHeaderPreviewProps) {
  const { t } = useTranslation()
  const format = getHeaderMediaFormat(template.components)
  const assetId = !file && !url ? template.default_header_asset_id ?? null : null
  const assetUrl = useImagePreviewUrl(format === "IMAGE" && assetId ? { source: "media_asset", media_asset_id: assetId } : null)
  const fileUrl = useFileDataUrl(format === "IMAGE" ? file : null)

  if (!format) return null

  if (format === "IMAGE") {
    const src = file ? fileUrl : url ?? assetUrl ?? (assetId ? null : templateHeaderExampleUrl(template.components))
    return src ? (
      // eslint-disable-next-line @next/next/no-img-element
      <img src={src} alt="" className="aspect-[1.91/1] w-full rounded-md object-cover" />
    ) : (
      <div className="flex aspect-[1.91/1] w-full items-center justify-center rounded-md bg-muted text-muted-foreground">
        <ImageIcon className="size-6" aria-hidden />
      </div>
    )
  }

  const Icon = format === "VIDEO" ? Video : FileText
  const label = file?.name
    ?? (url ? new URL(url).pathname.split("/").pop() || url : null)
    ?? (assetId ? t("chats.broadcast.dialog.media.templateFile") : t("chats.broadcast.dialog.media.noFile"))
  return (
    <div className="flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
      <Icon className="size-4 shrink-0" aria-hidden />
      <span className="truncate">{label}</span>
    </div>
  )
}

/** Data URL de un archivo elegido en el navegador, leída sin bloquear el render. */
function useFileDataUrl(file: File | null): string | null {
  const [loaded, setLoaded] = useState<{ file: File; url: string } | null>(null)

  useEffect(() => {
    if (!file) return
    const reader = new FileReader()
    reader.onload = () => setLoaded({ file, url: String(reader.result) })
    reader.readAsDataURL(file)
    return () => reader.abort()
  }, [file])

  return loaded && loaded.file === file ? loaded.url : null
}
