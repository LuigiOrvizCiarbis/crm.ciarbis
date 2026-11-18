"use client"

import { useEffect, useState } from "react"

import type { WhatsAppFlowImage } from "@/data/types"
import { fetchMediaAssetObjectUrl } from "@/lib/api/media-assets"

/**
 * URL que el navegador puede mostrar para la imagen de un mensaje.
 *
 * Un link https se muestra tal cual. Un archivo del espacio se baja con la sesión
 * (`/media-assets/{id}/download`): su URL pública es la que usa Meta y en un entorno
 * local apunta a un túnel que el navegador puede no alcanzar.
 */
export function useImagePreviewUrl(image: WhatsAppFlowImage | null | undefined): string | null {
  const assetId = image?.source === "media_asset" ? image.media_asset_id : null
  const [loaded, setLoaded] = useState<{ id: number; url: string } | null>(null)

  useEffect(() => {
    if (assetId === null) return
    const controller = new AbortController()
    let objectUrl: string | null = null
    fetchMediaAssetObjectUrl(assetId, controller.signal)
      .then((url) => {
        objectUrl = url
        setLoaded({ id: assetId, url })
      })
      // Sin acceso o sin archivo la vista previa muestra el ícono de imagen; el guardado ya valida el archivo.
      .catch(() => undefined)
    return () => {
      controller.abort()
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [assetId])

  if (image?.source === "literal") {
    const value = image.value.trim()
    return value.startsWith("https://") ? value : null
  }
  return loaded && loaded.id === assetId ? loaded.url : null
}
