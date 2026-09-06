"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { format } from "date-fns"
import { es } from "date-fns/locale"
import { Download, ExternalLink, FileText, RefreshCw, Trash2, TriangleAlert } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Skeleton } from "@/components/ui/skeleton"
import {
  AssetForbiddenError,
  AssetMissingError,
  fetchMediaAssetMeta,
  fetchMediaAssetObjectUrl,
  formatFileSize,
  saveBlobUrl,
} from "@/lib/api/media-assets"
import { fetchMessageMediaObjectUrl } from "@/lib/api/messages"
import { uploadContactDocument } from "@/lib/api/document-extractions"

/**
 * Origen del documento a mostrar. "asset" es un media asset (biblioteca de
 * automations o documento de contacto): tiene metadata propia vía /meta.
 * "message" es un adjunto de mensaje de chat: la metadata ya viaja con el
 * mensaje (media_filename, media_mime_type), no hace falta un round-trip
 * aparte.
 */
export type DocumentViewerSource =
  | { kind: "asset"; id: number }
  | { kind: "message"; id: number; filename?: string | null; mimeType?: string | null }

interface DocumentViewerSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  source: DocumentViewerSource | null
  /** Etiqueta de contexto adicional (ej. nombre del campo custom). */
  fieldLabel?: string
  /** Sólo tiene sentido para source "asset": reemplazo y borrado del campo. */
  onValueChange?: (assetId: number | null) => void
  /** Requerido junto con onValueChange para habilitar reemplazo/borrado. */
  contactId?: number | null
}

type LoadState =
  | { kind: "loading" }
  | { kind: "ready"; url: string }
  | { kind: "missing" }
  | { kind: "forbidden" }
  | { kind: "error"; message: string }

interface ResolvedMeta {
  name: string
  size: number | null
  createdAt: string | null
  uploadedBy: string | null
}

/**
 * Visor de documentos compartido entre la ficha de contacto y el chat.
 *
 * El PDF se pide con fetch autenticado y se muestra desde una URL de objeto:
 * el endpoint exige un header que un <object data="..."> no puede enviar.
 * Ambos orígenes (asset y message) terminan en el mismo patrón fetch → blob,
 * sólo cambia qué función arma la request.
 */
export function DocumentViewerSheet({
  open,
  onOpenChange,
  source,
  fieldLabel,
  onValueChange,
  contactId = null,
}: DocumentViewerSheetProps) {
  const [meta, setMeta] = useState<ResolvedMeta | null>(null)
  const [state, setState] = useState<LoadState>({ kind: "loading" })
  const [replacing, setReplacing] = useState(false)
  const [confirmingRemove, setConfirmingRemove] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const fileInputRef = useRef<HTMLInputElement>(null)
  // La URL de objeto se revoca a mano: si no, el blob queda retenido mientras
  // viva la pestaña, y un contrato escaneado son varios MB por cada apertura.
  const objectUrlRef = useRef<string | null>(null)

  const releaseObjectUrl = useCallback(() => {
    if (objectUrlRef.current) {
      URL.revokeObjectURL(objectUrlRef.current)
      objectUrlRef.current = null
    }
  }, [])

  const load = useCallback(async (src: DocumentViewerSource, signal: AbortSignal) => {
    setState({ kind: "loading" })
    setActionError(null)

    if (src.kind === "asset") {
      try {
        const assetMeta = await fetchMediaAssetMeta(src.id)
        if (signal.aborted) return
        setMeta({
          name: assetMeta.name,
          size: assetMeta.size,
          createdAt: assetMeta.created_at,
          uploadedBy: assetMeta.uploaded_by,
        })
      } catch (err) {
        if (signal.aborted) return
        if (err instanceof AssetForbiddenError) {
          setState({ kind: "forbidden" })
          return
        }
        setMeta(null)
      }
    } else {
      // La metadata de un adjunto de mensaje ya viaja con el mensaje: no hay
      // endpoint /meta aparte para este origen.
      setMeta({
        name: src.filename || "documento.pdf",
        size: null,
        createdAt: null,
        uploadedBy: null,
      })
    }

    if (signal.aborted) return

    try {
      const url =
        src.kind === "asset"
          ? await fetchMediaAssetObjectUrl(src.id, signal)
          : await fetchMessageMediaObjectUrl(src.id, signal)
      if (signal.aborted) {
        URL.revokeObjectURL(url)
        return
      }
      releaseObjectUrl()
      objectUrlRef.current = url
      setState({ kind: "ready", url })
    } catch (err) {
      if (signal.aborted) return
      if (err instanceof AssetMissingError) setState({ kind: "missing" })
      else if (err instanceof AssetForbiddenError) setState({ kind: "forbidden" })
      else setState({ kind: "error", message: err instanceof Error ? err.message : "No se pudo abrir el archivo." })
    }
  }, [releaseObjectUrl])

  useEffect(() => {
    if (!open || !source) return

    const controller = new AbortController()
    void load(source, controller.signal)

    return () => {
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, source?.kind, source?.id, load])

  // El blob se libera al cerrar, no al desmontar: el componente vive mientras
  // viva la lista/hilo que lo contiene.
  useEffect(() => {
    if (!open) {
      releaseObjectUrl()
      setState({ kind: "loading" })
      setMeta(null)
      setConfirmingRemove(false)
      setActionError(null)
    }
  }, [open, releaseObjectUrl])

  useEffect(() => releaseObjectUrl, [releaseObjectUrl])

  const canManageAsset = source?.kind === "asset" && !!onValueChange

  const handleReplace = async (file: File | undefined) => {
    if (!file || contactId === null || !onValueChange) return
    setReplacing(true)
    setActionError(null)
    try {
      const uploaded = await uploadContactDocument(contactId, file)
      onValueChange(uploaded.id)
    } catch (err) {
      // El upload va por el endpoint de extracción, que además del acceso al
      // contacto exige el permiso de la feature: quien puede leer el documento
      // no necesariamente puede reemplazarlo.
      const message = err instanceof Error ? err.message : "No se pudo subir el archivo."
      setActionError(
        message.toLowerCase().includes("unauthorized") || message.includes("403")
          ? "No tenés permiso para reemplazar este archivo."
          : message,
      )
    } finally {
      setReplacing(false)
      if (fileInputRef.current) fileInputRef.current.value = ""
    }
  }

  const handleRemove = () => {
    onValueChange?.(null)
    onOpenChange(false)
  }

  const fileName = meta?.name ?? "documento.pdf"
  const details = [
    formatFileSize(meta?.size),
    meta?.createdAt ? format(new Date(meta.createdAt), "d MMM yyyy", { locale: es }) : null,
    meta?.uploadedBy,
  ].filter(Boolean)

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      {/* Ancho generoso: una hoja A4 dentro de 24rem es ilegible. */}
      <SheetContent className="w-full gap-0 p-0 sm:max-w-3xl">
        {canManageAsset && (
          // Fuera de la barra inferior: el estado "archivo faltante" también
          // ofrece subir, y ahí la barra no se renderiza.
          <input
            ref={fileInputRef}
            type="file"
            accept="application/pdf"
            className="sr-only"
            onChange={(e) => void handleReplace(e.target.files?.[0])}
          />
        )}

        <SheetHeader className="gap-1 border-b px-6 pt-6 pb-4">
          {/* pr-10 deja lugar al botón de cerrar que SheetContent posiciona absoluto. */}
          <SheetTitle className="truncate pr-10 text-base" title={fileName}>
            {fileName}
          </SheetTitle>
          {/* Alto fijo: la metadata llega después del primer render y sin esto
              la línea aparece de golpe y empuja el visor hacia abajo. */}
          <SheetDescription asChild>
            <div className="flex h-4 items-center text-xs">
              {meta === null && state.kind === "loading" ? (
                <Skeleton className="h-3 w-48" />
              ) : (
                <span className="truncate">
                  {fieldLabel ? <span className="text-foreground/70">{fieldLabel}</span> : null}
                  {fieldLabel && details.length > 0 ? " · " : null}
                  {details.join(" · ")}
                </span>
              )}
            </div>
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 overflow-hidden bg-muted/40">
          {state.kind === "loading" && (
            // Skeleton con la forma del documento, no un spinner: reserva el
            // alto y evita que el visor entre de golpe.
            <div className="h-full p-6">
              <Skeleton className="h-full w-full rounded-md" />
            </div>
          )}

          {state.kind === "ready" && (
            <object data={state.url} type="application/pdf" className="h-full w-full" aria-label={`Documento ${fileName}`}>
              {/* Fallback del propio <object>: lo muestra el navegador que no
                  puede embeber PDFs. No es un error. */}
              <div className="flex h-full flex-col items-center justify-center gap-3 px-6 text-center">
                <FileText className="h-8 w-8 text-muted-foreground" aria-hidden />
                <p className="text-sm text-muted-foreground">Tu navegador no puede mostrar este PDF acá.</p>
                <Button variant="outline" size="sm" onClick={() => window.open(state.url, "_blank", "noopener")}>
                  <ExternalLink className="h-4 w-4" aria-hidden />
                  Abrir en pestaña nueva
                </Button>
              </div>
            </object>
          )}

          {state.kind === "missing" && (
            <StatusPanel
              title="El archivo ya no está disponible."
              description="La referencia quedó apuntando a un archivo que no está en el espacio."
              action={
                canManageAsset && contactId !== null ? (
                  // Subir de nuevo va primero: el caso habitual es que el
                  // documento exista y sea la referencia la que quedó mal.
                  // Quitar deja el campo vacío, así que es la salida secundaria.
                  <div className="flex items-center gap-2">
                    <Button size="sm" disabled={replacing} onClick={() => fileInputRef.current?.click()}>
                      <RefreshCw className={`h-4 w-4 ${replacing ? "animate-spin" : ""}`} aria-hidden />
                      {replacing ? "Subiendo…" : "Subir de nuevo"}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={handleRemove}>
                      Quitar referencia
                    </Button>
                  </div>
                ) : null
              }
            />
          )}

          {state.kind === "forbidden" && (
            <StatusPanel
              title="No tenés acceso a este archivo."
              description="Pedile a un administrador que te asigne el contacto."
            />
          )}

          {state.kind === "error" && <StatusPanel title="No se pudo abrir el archivo." description={state.message} />}
        </div>

        <div className="flex flex-wrap items-center gap-2 border-t px-6 py-4">
          <Button
            size="sm"
            disabled={state.kind !== "ready"}
            onClick={() => state.kind === "ready" && saveBlobUrl(state.url, fileName)}
          >
            <Download className="h-4 w-4" aria-hidden />
            Descargar
          </Button>

          {canManageAsset && (
            <>
              <Button
                variant="outline"
                size="sm"
                disabled={replacing}
                onClick={() => fileInputRef.current?.click()}
              >
                <RefreshCw className={`h-4 w-4 ${replacing ? "animate-spin" : ""}`} aria-hidden />
                {replacing ? "Reemplazando…" : "Reemplazar"}
              </Button>

              {confirmingRemove ? (
                // La confirmación vive en la barra, no en un modal encima del
                // sheet ni en un confirm() del navegador.
                <div className="flex items-center gap-2">
                  <span className="text-sm text-muted-foreground">¿Quitar el archivo de este campo?</span>
                  <Button variant="destructive" size="sm" onClick={handleRemove}>
                    Quitar
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => setConfirmingRemove(false)}>
                    Cancelar
                  </Button>
                </div>
              ) : (
                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-auto text-muted-foreground hover:text-destructive"
                  onClick={() => setConfirmingRemove(true)}
                >
                  <Trash2 className="h-4 w-4" aria-hidden />
                  Quitar
                </Button>
              )}
            </>
          )}
        </div>

        {actionError && (
          <p className="flex items-center gap-2 border-t px-6 py-3 text-sm text-destructive" role="alert">
            <TriangleAlert className="h-4 w-4 shrink-0" aria-hidden />
            {actionError}
          </p>
        )}
      </SheetContent>
    </Sheet>
  )
}

function StatusPanel({
  title,
  description,
  action,
}: {
  title: string
  description: string
  action?: React.ReactNode
}) {
  return (
    <div className="flex h-full flex-col items-center justify-center gap-3 px-6 text-center">
      <TriangleAlert className="h-8 w-8 text-muted-foreground" aria-hidden />
      <div className="space-y-1">
        <p className="text-sm font-medium text-foreground">{title}</p>
        <p className="max-w-sm text-sm text-muted-foreground">{description}</p>
      </div>
      {action}
    </div>
  )
}
