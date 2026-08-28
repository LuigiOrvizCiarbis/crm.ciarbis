import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";

export type ContactFieldType =
  | "text"
  | "number"
  | "currency"
  | "date"
  | "boolean"
  | "select"
  | "multi_select"
  | "email"
  | "url"
  | "phone"
  | "file"
  | "repeater";

export interface ContactFieldOptions {
  choices?: string[];
  /** Divisa de un campo tipo moneda (ARS | USD). El valor guardado es el importe pelado. */
  currency?: string;
  fields?: RepeaterSubfield[];
  min_items?: number;
  max_items?: number;
}

export type RepeaterSubfieldType = Exclude<ContactFieldType, "file" | "multi_select" | "repeater">;

export interface RepeaterSubfield {
  key?: string;
  label: string;
  type: RepeaterSubfieldType;
  options?: { choices?: string[]; currency?: string } | null;
  is_required?: boolean;
  is_active?: boolean;
}

export interface ContactField {
  id: number;
  key: string;
  label: string;
  type: ContactFieldType;
  options: ContactFieldOptions | null;
  is_required: boolean;
  is_unique: boolean;
  is_system: boolean;
  display_order: number;
  created_at?: string;
  updated_at?: string;
}

export interface ContactFieldInput {
  label: string;
  type: ContactFieldType;
  options?: ContactFieldOptions | null;
  is_required?: boolean;
  is_unique?: boolean;
}

export interface ContactFieldUpdate {
  label?: string;
  options?: ContactFieldOptions | null;
  is_required?: boolean;
  is_unique?: boolean;
  display_order?: number;
}

export interface ContactFieldsResponse {
  data: ContactField[];
  standard: Array<Omit<ContactField, "id" | "created_at" | "updated_at"> & { id?: undefined }>;
}

function headers(): HeadersInit {
  const token = getAuthToken();
  return {
    Authorization: token ? `Bearer ${token}` : "",
    Accept: "application/json",
    "Content-Type": "application/json",
  };
}

export async function getContactFields(): Promise<ContactFieldsResponse> {
  const token = getAuthToken();
  if (!token) return { data: [], standard: [] };

  const response = await fetch("/api/contact-fields", {
    headers: headers(),
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al cargar campos de contacto");

  return payload as ContactFieldsResponse;
}

export async function createContactField(input: ContactFieldInput): Promise<ContactField> {
  const response = await fetch("/api/contact-fields", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify(input),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al crear campo");

  return payload.data as ContactField;
}

export async function updateContactField(id: number, patch: ContactFieldUpdate): Promise<ContactField> {
  const response = await fetch(`/api/contact-fields/${id}`, {
    method: "PUT",
    headers: headers(),
    body: JSON.stringify(patch),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al actualizar campo");

  return payload.data as ContactField;
}

export async function deleteContactField(id: number): Promise<void> {
  const response = await fetch(`/api/contact-fields/${id}`, {
    method: "DELETE",
    headers: headers(),
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throwApiError(response.status, payload, "Error al eliminar campo");
  }
}

export async function reorderContactFields(items: Array<{ id: number; display_order: number }>): Promise<void> {
  const response = await fetch("/api/contact-fields/reorder", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ items }),
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throwApiError(response.status, payload, "Error al reordenar campos");
  }
}

export type ContactFieldPreset = "rental_contract";

export interface ApplyPresetResult {
  data: ContactField[];
  /** Keys creadas por el preset. */
  created: string[];
  /** Keys que ya existían: no se duplican ni se pisan. */
  existing: string[];
}

/**
 * Crea de una vez los campos de una plantilla (por ejemplo un contrato de
 * alquiler). Quedan como campos normales: editables, reordenables y borrables.
 */
export async function applyContactFieldPreset(preset: ContactFieldPreset): Promise<ApplyPresetResult> {
  const response = await fetch("/api/contact-fields/apply-preset", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ preset }),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al aplicar la plantilla");

  return payload as ApplyPresetResult;
}
