import { throwApiError } from "./api-error";
import type { ContactFieldOptions, ContactFieldType } from "./contact-fields";

// Product custom fields share the exact same shape as contact custom fields.
// The field-type enum and options are reused so CustomFieldInput and the
// dynamic validation schema work across both entities unchanged.
export type ProductFieldType = ContactFieldType;
export type ProductFieldOptions = ContactFieldOptions;

export interface ProductField {
  id: number;
  key: string;
  label: string;
  type: ProductFieldType;
  options: ProductFieldOptions | null;
  is_required: boolean;
  is_unique: boolean;
  is_system: boolean;
  display_order: number;
  created_at?: string;
  updated_at?: string;
}

export interface ProductFieldInput {
  label: string;
  type: ProductFieldType;
  options?: ProductFieldOptions | null;
  is_required?: boolean;
  is_unique?: boolean;
}

export interface ProductFieldUpdate {
  label?: string;
  options?: ProductFieldOptions | null;
  is_required?: boolean;
  is_unique?: boolean;
  display_order?: number;
}

export interface ProductFieldsResponse {
  data: ProductField[];
  standard: Array<Omit<ProductField, "id" | "created_at" | "updated_at"> & { id?: undefined }>;
}

function headers(): HeadersInit {
  return {
    Accept: "application/json",
    "Content-Type": "application/json",
  };
}

export async function getProductFields(): Promise<ProductFieldsResponse> {
  const response = await fetch("/api/product-fields", {
    headers: headers(),
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al cargar campos de producto");

  return payload as ProductFieldsResponse;
}

export async function createProductField(input: ProductFieldInput): Promise<ProductField> {
  const response = await fetch("/api/product-fields", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify(input),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al crear campo");

  return payload.data as ProductField;
}

export async function updateProductField(id: number, patch: ProductFieldUpdate): Promise<ProductField> {
  const response = await fetch(`/api/product-fields/${id}`, {
    method: "PUT",
    headers: headers(),
    body: JSON.stringify(patch),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "Error al actualizar campo");

  return payload.data as ProductField;
}

export async function deleteProductField(id: number): Promise<void> {
  const response = await fetch(`/api/product-fields/${id}`, {
    method: "DELETE",
    headers: headers(),
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throwApiError(response.status, payload, "Error al eliminar campo");
  }
}

export async function reorderProductFields(items: Array<{ id: number; display_order: number }>): Promise<void> {
  const response = await fetch("/api/product-fields/reorder", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ items }),
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throwApiError(response.status, payload, "Error al reordenar campos");
  }
}
