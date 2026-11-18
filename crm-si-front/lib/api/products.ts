import { throwApiError } from "./api-error";

export interface ProductVariant {
  id: number;
  external_id: string;
  sku: string | null;
  // p. ej. { Talle: "M" }; un valor vacío = cualquier opción.
  attributes: Record<string, string>;
  price: string | null;
  stock_quantity: number | null;
  // Estado de stock de Woo: instock/outofstock/onbackorder o uno de una extensión.
  stock_status: string | null;
  image_url: string | null;
}

export interface Product {
  id: number;
  name: string;
  price: string | null;
  description: string | null;
  is_active: boolean;
  source: string;
  external_id: string | null;
  external_type: string | null;
  requires_shipping: boolean;
  custom_data?: Record<string, unknown>;
  // Columnas/campos que gestiona una integración (WooCommerce): no se editan acá.
  managed_fields: string[];
  // El listado trae solo la cantidad; las variantes vienen en el detalle.
  variants_count?: number;
  variants?: ProductVariant[];
  created_at?: string;
  updated_at?: string;
}

export interface ProductInput {
  name: string;
  price?: number | null;
  description?: string | null;
  is_active?: boolean;
  custom_data?: Record<string, unknown>;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...options.headers,
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al procesar productos");
  }

  return payload as T;
}

export async function getProducts(params: { search?: string; is_active?: boolean } = {}): Promise<Product[]> {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.is_active !== undefined) query.set("is_active", params.is_active ? "1" : "0");

  const payload = await request<{ data: Product[] }>(`/api/products${query.toString() ? `?${query}` : ""}`);

  return payload.data;
}

export async function getProduct(productId: number): Promise<Product> {
  const payload = await request<{ data: Product }>(`/api/products/${productId}`);

  return payload.data;
}

export async function createProduct(input: ProductInput): Promise<Product> {
  const payload = await request<{ data: Product }>("/api/products", {
    method: "POST",
    body: JSON.stringify(input),
  });

  return payload.data;
}

export async function updateProduct(productId: number, input: Partial<ProductInput>): Promise<Product> {
  const payload = await request<{ data: Product }>(`/api/products/${productId}`, {
    method: "PUT",
    body: JSON.stringify(input),
  });

  return payload.data;
}

export async function deleteProduct(productId: number): Promise<void> {
  await request(`/api/products/${productId}`, { method: "DELETE" });
}
