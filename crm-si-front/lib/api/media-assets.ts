import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export interface MediaAsset {
  id: number
  name: string
  mime_type: string
  size: number
  url: string
  created_at: string
}

export async function uploadMediaAsset(file: File): Promise<MediaAsset> {
  const token = getAuthToken()
  if (!token) throw new Error("No authentication token found")

  const formData = new FormData()
  formData.append("file", file)

  const res = await fetch("/api/media-assets", {
    method: "POST",
    headers: { Authorization: `Bearer ${token}` },
    body: formData,
  })

  const data = await res.json().catch(() => ({}))
  if (!res.ok) throwApiError(res.status, data, "Error al subir el archivo")

  return data.data
}

export interface MediaAssetMeta {
  id: number
  name: string
  mime_type: string | null
  size: number | null
  uploaded_by: string | null
  created_at: string | null
  /** false cuando la fila existe pero el archivo ya no está en disco. */
  available: boolean
}

/**
 * El archivo no está: la fila sobrevivió al borrado del disco, o la referencia
 * en custom_data quedó apuntando a un id que ya no existe.
 */
export class AssetMissingError extends Error {
  constructor(message = "El archivo ya no está disponible.") {
    super(message)
    this.name = "AssetMissingError"
  }
}

/** El usuario no tiene acceso a este archivo (contacto no asignado, por ejemplo). */
export class AssetForbiddenError extends Error {
  constructor(message = "No tenés acceso a este archivo.") {
    super(message)
    this.name = "AssetForbiddenError"
  }
}

function authHeader(): HeadersInit {
  const token = getAuthToken()
  return { Authorization: token ? `Bearer ${token}` : "" }
}

export async function fetchMediaAssetMeta(assetId: number): Promise<MediaAssetMeta> {
  const res = await fetch(`/api/media-assets/${assetId}/meta`, {
    headers: { ...authHeader(), Accept: "application/json" },
  })

  if (res.status === 404) throw new AssetMissingError()
  if (res.status === 403) throw new AssetForbiddenError()

  const data = await res.json().catch(() => ({}))
  if (!res.ok) throwApiError(res.status, data, "No se pudo leer el archivo")

  return data.data as MediaAssetMeta
}

/**
 * Descarga el archivo y devuelve una URL de objeto para el visor.
 *
 * El endpoint exige el token en un header y un <object data="..."> no puede
 * mandarlo: el navegador pediría la URL sin credenciales. Por eso se baja con
 * fetch autenticado y se le da al visor un blob:, que vive sólo en esta pestaña
 * y muere al revocarlo.
 *
 * Quien llame es responsable de URL.revokeObjectURL() al desmontar.
 */
export async function fetchMediaAssetObjectUrl(assetId: number, signal?: AbortSignal): Promise<string> {
  const res = await fetch(`/api/media-assets/${assetId}/download`, {
    headers: authHeader(),
    signal,
  })

  if (res.status === 404) throw new AssetMissingError()
  if (res.status === 403) throw new AssetForbiddenError()

  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throwApiError(res.status, data, "No se pudo abrir el archivo")
  }

  return URL.createObjectURL(await res.blob())
}

/**
 * Guarda el archivo en el disco del usuario.
 *
 * Se reusa el blob que el visor ya tiene en memoria en vez de pedirlo otra vez:
 * un link directo al endpoint no llevaría el header de autorización.
 */
export function saveBlobUrl(objectUrl: string, fileName: string): void {
  const link = document.createElement("a")
  link.href = objectUrl
  link.download = fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
}

/** Tamaño legible. Devuelve null si el backend no lo tiene registrado. */
export function formatFileSize(bytes: number | null | undefined): string | null {
  if (bytes === null || bytes === undefined) return null
  if (bytes < 1024) return `${bytes} B`
  const units = ["KB", "MB", "GB"]
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit += 1
  }
  return `${value.toFixed(value < 10 ? 1 : 0).replace(".", ",")} ${units[unit]}`
}
