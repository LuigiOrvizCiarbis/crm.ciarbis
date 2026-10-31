"use client"

import { DocumentViewerSheet as GenericDocumentViewerSheet } from "@/components/ui/DocumentViewerSheet"

interface DocumentViewerSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  assetId: number | null
  contactId: number | null
  /** Etiqueta del campo custom que contiene el archivo. Da contexto en el título. */
  fieldLabel?: string
  /** Reemplazo y borrado escriben el nuevo valor del campo (o null al quitarlo). */
  onValueChange?: (assetId: number | null) => void
}

/**
 * Wrapper de compatibilidad: la ficha de contacto sigue hablando en términos
 * de assetId/contactId, el visor genérico (compartido con el chat) habla en
 * términos de `source`. La lógica de visor vive en components/ui/DocumentViewerSheet.
 */
export function DocumentViewerSheet({
  open,
  onOpenChange,
  assetId,
  contactId,
  fieldLabel,
  onValueChange,
}: DocumentViewerSheetProps) {
  return (
    <GenericDocumentViewerSheet
      open={open}
      onOpenChange={onOpenChange}
      source={assetId !== null ? { kind: "asset", id: assetId } : null}
      contactId={contactId}
      fieldLabel={fieldLabel}
      onValueChange={onValueChange}
    />
  )
}
