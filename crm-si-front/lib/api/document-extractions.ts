import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";

export type ExtractionStatus = "queued" | "processing" | "completed" | "failed" | "confirmed";

export type TextCoverage = "full" | "partial";

export interface DocumentExtraction {
  id: number;
  status: ExtractionStatus;
  /** Campos extraídos: {key: valor}. null cuando el dato no estaba en el documento. */
  result: Record<string, unknown> | null;
  text_coverage: TextCoverage | null;
  /** Páginas sin texto extraíble. Un campo vacío puede estar en una de ellas. */
  pages_without_text: number[];
  error_code: string | null;
  error_message: string | null;
  /** Versión del contacto al iniciar. Se manda al confirmar para detectar ediciones. */
  contact_lock_version: number | null;
  /** Texto del contrato, sólo en el detalle: es lo que el usuario compara. */
  document_text?: string | null;
  created_at?: string;
}

export interface UploadedDocument {
  id: number;
  name: string;
  size: number;
}

export interface ConfirmExtractionResult {
  data: DocumentExtraction;
  applied: string[];
  /** Claves descartadas porque su campo se borró mientras se revisaba. */
  discarded: string[];
  contact?: { lock_version: number; custom_data: Record<string, unknown> };
}

function headers(): HeadersInit {
  const token = getAuthToken();
  return {
    Authorization: token ? `Bearer ${token}` : "",
    Accept: "application/json",
    "Content-Type": "application/json",
  };
}

/**
 * Sube el PDF sobre el contacto. Endpoint propio y no /media-assets: aquel
 * exige el permiso de automatizaciones, que el rol operativo no tiene.
 */
export async function uploadContactDocument(contactId: number, file: File): Promise<UploadedDocument> {
  const token = getAuthToken();
  const formData = new FormData();
  formData.append("file", file);

  const response = await fetch(`/api/contacts/${contactId}/documents`, {
    method: "POST",
    // Sin Content-Type: el browser pone el boundary del multipart.
    headers: { Authorization: token ? `Bearer ${token}` : "" },
    body: formData,
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al subir el documento");

  return payload.data as UploadedDocument;
}

export async function startExtraction(contactId: number, mediaAssetId: number): Promise<DocumentExtraction> {
  const response = await fetch(`/api/contacts/${contactId}/extractions`, {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ media_asset_id: mediaAssetId }),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al iniciar la extracción");

  return payload.data as DocumentExtraction;
}

export async function getExtraction(contactId: number, extractionId: number): Promise<DocumentExtraction> {
  const response = await fetch(`/api/contacts/${contactId}/extractions/${extractionId}`, {
    headers: headers(),
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al consultar la extracción");

  return payload.data as DocumentExtraction;
}

/**
 * Aplica al contacto los campos que el usuario dejó tildados.
 *
 * Un 409 significa que el contacto cambió desde que arrancó la extracción: hay
 * que recargar y decidir, no reintentar a ciegas.
 */
export class StaleContactError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "StaleContactError";
  }
}

export async function confirmExtraction(
  contactId: number,
  extractionId: number,
  fields: Record<string, unknown>,
  lockVersion: number,
): Promise<ConfirmExtractionResult> {
  const response = await fetch(`/api/contacts/${contactId}/extractions/${extractionId}/confirm`, {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ fields, lock_version: lockVersion }),
  });

  const payload = await response.json().catch(() => ({}));

  // throwApiError lanza un Error plano sin el status, así que el conflicto se
  // distingue acá: la UI tiene que ofrecer recargar, no reintentar a ciegas.
  if (response.status === 409) {
    throw new StaleContactError(
      payload?.message || "El contacto fue modificado desde que empezó la extracción.",
    );
  }

  if (!response.ok) throwApiError(response.status, payload, "Error al confirmar los datos");

  return payload as ConfirmExtractionResult;
}

/** Mensajes accionables por código de error del backend. */
export const EXTRACTION_ERRORS: Record<string, string> = {
  no_text_layer:
    "El PDF no tiene texto seleccionable: parece un documento escaneado. Probá con el archivo original.",
  document_too_large: "El documento es demasiado grande para procesarlo.",
  too_many_pages: "El documento tiene demasiadas páginas.",
  not_a_pdf: "El archivo no es un PDF válido.",
  encrypted: "El PDF está protegido con contraseña.",
  asset_missing: "El documento ya no está disponible.",
  ai_not_configured: "Configurá una API key de IA para usar la extracción.",
  ai_disabled: "La IA está desactivada para este espacio.",
  unsupported: "La extracción de documentos requiere Claude como proveedor de IA.",
  no_fields: "Configurá campos personalizados de contacto antes de extraer datos.",
  invalid_key: "La API key de IA no es válida.",
  no_credit: "La cuenta de IA no tiene saldo disponible.",
  rate_limit: "El proveedor de IA está saturado. Probá de nuevo en unos minutos.",
  invalid_output: "El modelo no devolvió los datos en el formato esperado.",
  stalled: "La extracción se interrumpió. Probá de nuevo.",
  extraction_timeout: "La lectura del PDF tardó demasiado.",
  extraction_failed: "No se pudo leer el contenido del PDF.",
};

export function extractionErrorMessage(extraction: DocumentExtraction): string {
  if (extraction.error_code && EXTRACTION_ERRORS[extraction.error_code]) {
    return EXTRACTION_ERRORS[extraction.error_code];
  }
  return extraction.error_message || "No se pudo completar la extracción.";
}
