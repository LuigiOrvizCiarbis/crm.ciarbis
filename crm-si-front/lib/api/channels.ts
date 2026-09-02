import { Channel } from "@/data/types";
import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";
import { ChannelError } from "@/lib/channel-error";

export type MailEncryption = "ssl" | "tls" | "none";

export interface MailConnectPayload {
  email_address: string;
  password: string;
  from_name?: string;
  imap_host: string;
  imap_port: number;
  imap_encryption: MailEncryption;
  smtp_host: string;
  smtp_port: number;
  smtp_encryption: MailEncryption;
}

/**
 * Conecta una casilla de email genérica (IMAP/SMTP) como canal MAIL.
 * Sigue las convenciones de `channel-error`: los proxies mandan `code` (el
 * front lo traduce) y Laravel manda `message` (texto ya redactado), salvo el
 * 409 de casilla duplicada que se traduce con clave propia.
 */
export async function connectMailChannel(payload: MailConnectPayload): Promise<Channel> {
  const token = getAuthToken();
  if (!token) throw new ChannelError({ code: "channelErrorSessionExpired" });

  let response: Response;
  try {
    response = await fetch("/api/mail-auth", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(payload),
    });
  } catch {
    // Error de red: el front muestra un mensaje traducido.
    throw new ChannelError({ code: "channelErrorNetwork" });
  }

  const data = await response.json().catch(() => ({}));

  if (response.ok) {
    return data.data ?? data;
  }

  if (response.status === 409) throw new ChannelError({ code: "channelErrorMailAlreadyConnected" });
  if (data.code) throw new ChannelError({ code: data.code });
  if (data.message) throw new ChannelError({ message: data.message });
  throw new ChannelError({ code: "channelErrorMail" });
}

/**
 * Fuerza una sincronización IMAP del canal de email. El backend encola el job
 * y responde enseguida: la llegada de los mensajes es asincrónica (websocket).
 */
export async function syncMailChannel(channelId: number): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new ChannelError({ code: "channelErrorSessionExpired" });

  let response: Response;
  try {
    response = await fetch(`/api/mail-sync/${channelId}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
      },
    });
  } catch {
    throw new ChannelError({ code: "channelErrorNetwork" });
  }

  if (response.ok) return;

  const data = await response.json().catch(() => ({}));
  if (data.code) throw new ChannelError({ code: data.code });
  if (data.message) throw new ChannelError({ message: data.message });
  throw new ChannelError({ code: "channelErrorMailSync" });
}

export async function getChannels(): Promise<Channel[]> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch("/api/channels", {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "Error al cargar canales");
  }

  const json = await response.json();

  return json.data ?? [];
}

export type ContactSyncStatus =
  | "completed"
  | "syncing"
  | "pending"
  | "failed"
  | "not_applicable";

// Mismo patrón que AiTestErrorCode (lib/api/ai-config.ts): un código tipado
// para que el front traduzca el mensaje en vez de mostrar el texto crudo de
// Meta. Por ahora sólo distingue rate_limit, el caso real que lo motivó: la
// cuota del business token del cliente (usada por smb_app_data) es invisible
// fuera de este flujo, y "Application request limit reached" no le dice al
// usuario qué hacer.
export type ContactSyncErrorCode = "rate_limit" | null;

export interface ContactSync {
  status: ContactSyncStatus;
  contacts_imported: number;
  requested_at: string | null;
  last_webhook_at: string | null;
  window_expires_at: string | null;
  can_retry: boolean;
  error: string | null;
  error_code: ContactSyncErrorCode;
  // El sync de contactos (agenda del teléfono) y el de historial
  // (conversaciones existentes) son dos eventos separados de Meta: un canal
  // puede traer mensajes de números que nunca estuvieron guardados en la
  // agenda. Van aparte para no ocultar cuál de los dos falló o cuál trajo
  // contenido.
  history_status: ContactSyncStatus | null;
  history_messages_imported: number;
  history_can_retry: boolean;
}

export async function getContactSync(channelId: number): Promise<ContactSync> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/channels/${channelId}/contact-sync`, {
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "Error al consultar la importación de contactos");
  }

  return (await response.json()).data;
}

export async function retryContactSync(channelId: number): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/channels/${channelId}/contact-sync/retry`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "No se pudo reintentar la importación");
  }
}

/**
 * Reintenta sólo el historial. Necesario porque el contact sync (agenda) y el
 * historial (conversaciones) son dos pasos independientes en Meta: si el
 * historial queda `failed` (p. ej. rate limit detectado antes del request)
 * mientras los contactos ya se completaron, retryContactSync devuelve 409 sin
 * tocar el historial — sin este endpoint separado, quedaría bloqueado para
 * siempre pese a que el mensaje de error invita a reintentar.
 */
export async function retryHistorySync(channelId: number): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/channels/${channelId}/contact-sync/retry-history`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "No se pudo reintentar la importación del historial");
  }
}

export async function updateChannelName(id: number, name: string): Promise<Channel> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/channels/${id}`, {
    method: "PATCH",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ name }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "Error al actualizar el canal");
  }

  const json = await response.json();

  return json.data;
}

export interface DisconnectChannelResult {
  data: Channel;
  warnings: string[];
}

/**
 * Desconexión "blanda": revoca la suscripción de webhooks en Meta
 * (best-effort del lado del backend) y purga credenciales, pero conserva
 * conversaciones, mensajes y difusiones. `warnings` no vacío indica, por
 * ejemplo, que las credenciales se conservaron por config compartida con
 * otro canal, o que la revocación remota no se pudo confirmar.
 */
export async function disconnectChannel(id: number): Promise<DisconnectChannelResult> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/channels/${id}/disconnect`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "No se pudo desconectar el canal");
  }

  const json = await response.json();

  return { data: json.data, warnings: json.warnings ?? [] };
}

export type BusinessVerificationStatus =
  | "verified"
  | "pending"
  | "not_verified"
  | "failed"
  | "business_id_missing"
  | "permission_missing"
  | "token_invalid"
  | "unknown";

export interface BusinessVerification {
  business_id: string | null;
  business_name: string | null;
  status: BusinessVerificationStatus;
  raw_verification_status: string | null;
  verify_url: string | null;
}

export async function getBusinessVerification(
  channelId: number
): Promise<BusinessVerification> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch(`/api/channels/${channelId}/business-verification`, {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "Error al obtener la verificación de negocio");
  }

  const json = await response.json();

  return json.data;
}
