import { Message, TranslationLanguage, SharedContact } from "@/data/types";
import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";
import { audioExtensionForMime } from "@/lib/audio";
import { AssetForbiddenError, AssetMissingError } from "./media-assets";

// El grabador guarda el blob como "video/webm" en algunos navegadores (mismo
// contenedor que un audio-only webm, pero el browser no distingue el track).
// Sin esto, un audio grabado se clasificaría por defecto como "image" y el
// backend lo rechazaría.
const AUDIO_LIKE_MIME_PREFIXES = ["audio/", "video/webm", "video/mp4"];

function resolveMediaType(file: File): "image" | "audio" {
  if (AUDIO_LIKE_MIME_PREFIXES.some((prefix) => file.type.startsWith(prefix))) {
    return "audio";
  }

  return "image";
}

/**
 * Nombra el File con una extensión coherente con su mime real antes de
 * mandarlo. El backend usa basename() al subir a Meta (WhatsAppMessageService),
 * y un blob grabado por MediaRecorder no trae nombre de archivo (File con
 * nombre genérico o sin extensión reconocible).
 */
function withAudioFilename(file: File): File {
  if (!file.type.startsWith("audio/") && !file.type.startsWith("video/webm") && !file.type.startsWith("video/mp4")) {
    return file;
  }

  const hasExtension = /\.[a-z0-9]+$/i.test(file.name);
  if (hasExtension) return file;

  const extension = audioExtensionForMime(file.type);
  const name = `nota-de-voz-${Date.now()}.${extension}`;
  return new File([file], name, { type: file.type });
}

export async function sendMessage(conversationId: number, content: string, media?: File, voice = false) {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  if (media) {
    const type = resolveMediaType(media);
    const outgoingFile = type === "audio" ? withAudioFilename(media) : media;
    const formData = new FormData();
    formData.append("conversation_id", String(conversationId));
    formData.append("type", type);
    formData.append(type, outgoingFile);
    if (voice) formData.append("voice", "1");
    if (content && type === "image") formData.append("content", content);

    const res = await fetch("/api/messages", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
      },
      body: formData,
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throwApiError(
        res.status,
        data,
        type === "audio" ? "No se pudo enviar el audio" : "No se pudo enviar la imagen"
      );
    }
    return data.data;
  }

  const res = await fetch("/api/messages", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({
      conversation_id: conversationId,
      content,
      type: "text",
    }),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throwApiError(res.status, data, "No se pudo enviar el mensaje");
  }

  return data.data;
}

export async function sendContactsMessage(conversationId: number, contactIds: number[]): Promise<Message> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");
  const response = await fetch("/api/messages", {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ conversation_id: conversationId, type: "contacts", contact_ids: contactIds }),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "No se pudieron enviar los contactos");
  return payload.data as Message;
}

export async function saveSharedContact(messageId: number, index: number, contactId?: number): Promise<unknown> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");
  const response = await fetch(`/api/messages/${messageId}/contacts/${index}/save`, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify(contactId ? { contact_id: contactId } : {}),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "No se pudo guardar el contacto");
  return payload.data;
}

export type { SharedContact };

export interface SendMailMessageInput {
  content: string;
  contentHtml?: string;
  cc?: string[];
  bcc?: string[];
  attachments?: File[];
}

export async function sendMailMessage(conversationId: number, input: SendMailMessageInput): Promise<Message> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  const formData = new FormData();
  formData.append("conversation_id", String(conversationId));
  formData.append("type", "mail");
  formData.append("content", input.content);
  if (input.contentHtml) formData.append("content_html", input.contentHtml);
  input.cc?.forEach((address) => formData.append("cc[]", address));
  input.bcc?.forEach((address) => formData.append("bcc[]", address));
  input.attachments?.forEach((file) => formData.append("attachments[]", file));

  const response = await fetch("/api/messages", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    body: formData,
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throwApiError(response.status, payload, "No se pudo enviar el email");
  return payload.data;
}

export async function getMessages(): Promise<Message[]> {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");

  const response = await fetch("/api/messages", {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throwApiError(response.status, error, "Error al cargar mensajes");
  }

  const json = await response.json();

  return json.data ?? [];
}

export async function editMessage(messageId: number, content: string): Promise<Message> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  const res = await fetch(`/api/messages/${messageId}`, {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ content }),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "No se pudo editar el mensaje");
  return data.data;
}

export async function deleteMessage(messageId: number): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  const res = await fetch(`/api/messages/${messageId}`, {
    method: "DELETE",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
  });

  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throwApiError(res.status, data, "No se pudo eliminar el mensaje");
  }
}

export interface MessageTranslationResponse {
  message_id: number;
  target_language: TranslationLanguage;
  translated_content: string;
  cached: boolean;
}

export async function translateMessage(
  messageId: number,
  targetLanguage: TranslationLanguage,
): Promise<MessageTranslationResponse> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  const res = await fetch(`/api/messages/${messageId}/translation`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ target_language: targetLanguage }),
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "No se pudo traducir el mensaje");
  return payload.data;
}

/**
 * Descarga el adjunto de un mensaje (documento/video) y devuelve una URL de
 * objeto para el visor. Mismo motivo que fetchMediaAssetObjectUrl: el endpoint
 * exige el token en un header, así que un <object>/<video> no puede pedirlo
 * directo. Quien llame es responsable de URL.revokeObjectURL().
 */
export async function fetchMessageMediaObjectUrl(messageId: number, signal?: AbortSignal): Promise<string> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  const res = await fetch(`/api/messages/${messageId}/media`, {
    headers: { Authorization: `Bearer ${token}` },
    signal,
  });

  if (res.status === 404) throw new AssetMissingError();
  if (res.status === 403) throw new AssetForbiddenError();

  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throwApiError(res.status, data, "No se pudo abrir el archivo");
  }

  return URL.createObjectURL(await res.blob());
}

/**
 * Reintenta el unfurl de un mensaje cuya preview quedó "failed" o "pending".
 * La preview "ok" llega sola por el broadcast de message.edited cuando el job
 * en cola la resuelve; esto es sólo para el botón de reintento manual.
 */
export async function retryLinkPreview(messageId: number): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new Error("Token faltante");

  const res = await fetch(`/api/messages/${messageId}/link-preview`, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}` },
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, payload, "No se pudo generar la vista previa del link");
}
