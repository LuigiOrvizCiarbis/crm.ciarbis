import type { ScheduledMessage } from "@/data/types";
import { throwApiError } from "./api-error";
import { audioExtensionForMime } from "@/lib/audio";
import { ChannelType } from "@/data/enums";

/** Espejo de OutboundMessageSender::SCHEDULABLE_CHANNELS: el back rechaza el resto con 422. */
const SCHEDULABLE_CHANNEL_TYPES = new Set<ChannelType>([ChannelType.WHATSAPP, ChannelType.INSTAGRAM, ChannelType.FACEBOOK]);

export function supportsScheduledMessages(channelType: ChannelType | null | undefined): boolean {
  return channelType != null && SCHEDULABLE_CHANNEL_TYPES.has(channelType);
}

/** Ventana de atención de Meta (MessagingWindowService::toArray). */
export interface MessagingWindow {
  /** false en grupos y canales que no son de Meta: no hay ventana. */
  applies: boolean
  /** Último entrante + 24h; null si el contacto nunca escribió. */
  expiresAt: string | null
  /** Tag HUMAN_AGENT habilitado (Instagram/Messenger con App Review aprobado). */
  humanAgentEnabled: boolean
  /** Último entrante + 7 días; null si Human Agent no aplica. */
  humanAgentExpiresAt: string | null
}

interface MessagingWindowPayload {
  applies: boolean
  expires_at: string | null
  human_agent_enabled: boolean
  human_agent_expires_at: string | null
}

export interface ScheduledMessagesResponse {
  data: ScheduledMessage[];
  window: MessagingWindow | null;
}

function toMessagingWindow(payload: MessagingWindowPayload | undefined): MessagingWindow | null {
  if (!payload) return null;
  return {
    applies: payload.applies,
    expiresAt: payload.expires_at,
    humanAgentEnabled: payload.human_agent_enabled,
    humanAgentExpiresAt: payload.human_agent_expires_at,
  };
}

export interface ScheduleMessageInput {
  content: string;
  scheduledFor: Date;
  media?: File;
  voice?: boolean;
}

function authHeaders(): Record<string, string> {
  return { Accept: "application/json" };
}

function isAudioFile(file: File): boolean {
  return ["audio/", "video/webm", "video/mp4"].some((prefix) => file.type.startsWith(prefix));
}

/** Mismo criterio que sendMessage: el backend usa el nombre al subir a Meta. */
function withAudioFilename(file: File): File {
  if (/\.[a-z0-9]+$/i.test(file.name)) return file;
  return new File([file], `nota-de-voz-${Date.now()}.${audioExtensionForMime(file.type)}`, { type: file.type });
}

function buildScheduleBody(input: ScheduleMessageInput): { body: BodyInit; json: boolean } {
  const scheduledFor = input.scheduledFor.toISOString();
  if (!input.media) {
    return { body: JSON.stringify({ type: "text", content: input.content, scheduled_for: scheduledFor }), json: true };
  }

  const type = isAudioFile(input.media) ? "audio" : "image";
  const formData = new FormData();
  formData.append("type", type);
  formData.append(type, type === "audio" ? withAudioFilename(input.media) : input.media);
  formData.append("scheduled_for", scheduledFor);
  if (input.voice) formData.append("voice", "1");
  if (input.content && type === "image") formData.append("content", input.content);
  return { body: formData, json: false };
}

export async function listScheduledMessages(conversationId: number): Promise<ScheduledMessagesResponse> {
  const res = await fetch(`/api/conversations/${conversationId}/scheduled-messages`, { headers: authHeaders() });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "No se pudieron cargar los mensajes programados");
  return { data: data.data ?? [], window: toMessagingWindow(data.meta?.messaging_window) };
}

export async function createScheduledMessage(
  conversationId: number,
  input: ScheduleMessageInput,
): Promise<ScheduledMessage> {
  const { body, json } = buildScheduleBody(input);
  const res = await fetch(`/api/conversations/${conversationId}/scheduled-messages`, {
    method: "POST",
    headers: json ? { ...authHeaders(), "Content-Type": "application/json" } : authHeaders(),
    body,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "No se pudo programar el mensaje");
  return data.data;
}

export async function updateScheduledMessage(
  id: number,
  changes: { content?: string; scheduledFor?: Date },
): Promise<ScheduledMessage> {
  const res = await fetch(`/api/scheduled-messages/${id}`, {
    method: "PUT",
    headers: { ...authHeaders(), "Content-Type": "application/json" },
    body: JSON.stringify({
      ...(changes.content !== undefined ? { content: changes.content } : {}),
      ...(changes.scheduledFor ? { scheduled_for: changes.scheduledFor.toISOString() } : {}),
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throwApiError(res.status, data, "No se pudo actualizar el mensaje programado");
  return data.data;
}

export async function cancelScheduledMessage(id: number): Promise<void> {
  const res = await fetch(`/api/scheduled-messages/${id}`, { method: "DELETE", headers: authHeaders() });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throwApiError(res.status, data, "No se pudo cancelar el mensaje programado");
  }
}
