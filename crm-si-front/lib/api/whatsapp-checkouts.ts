import type { Message, WhatsAppCheckoutSummary } from "@/data/types";
import { workspaceHeaders } from "./auth-token";
import { throwApiError } from "./api-error";

async function request(url: string, init: RequestInit, fallback: string): Promise<any> {
  const res = await fetch(url, {
    ...init,
    headers: { Accept: "application/json", "Content-Type": "application/json", ...workspaceHeaders() },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, fallback);
  return data;
}

/** Último pedido por WhatsApp de la conversación (últimos 30 días) o null. */
export async function getWhatsAppCheckout(conversationId: number): Promise<WhatsAppCheckoutSummary | null> {
  const data = await request(
    `/api/conversations/${conversationId}/whatsapp-checkout`,
    { method: "GET" },
    "Error al cargar el pedido",
  );
  return data?.data ?? null;
}

/** Envía el catálogo nativo de WhatsApp; sin `body` el back usa el texto por defecto. */
export async function sendWhatsAppCatalog(conversationId: number, body?: string): Promise<Message> {
  const data = await request(
    `/api/conversations/${conversationId}/whatsapp-catalog`,
    { method: "POST", body: JSON.stringify(body ? { body } : {}) },
    "Error al enviar el catálogo",
  );
  return data.data;
}
