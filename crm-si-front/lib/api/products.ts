import { throwApiError } from "./api-error";

export interface Product {
  id: number;
  name: string;
  price: string | null;
  description: string | null;
  is_active: boolean;
  source: string;
  external_id: string | null;
  custom_data?: Record<string, unknown>;
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
