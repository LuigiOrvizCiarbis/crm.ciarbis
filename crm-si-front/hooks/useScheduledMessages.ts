import { useCallback, useEffect, useState } from "react";
import type { Message, ScheduledMessage, ScheduledMessageStatus } from "@/data/types";
import { listScheduledMessages, type MessagingWindow } from "@/lib/api/scheduled-messages";

/** Estados que dejan de mostrarse en la barra: ya salió o se descartó. */
const HIDDEN_STATUSES = new Set<ScheduledMessageStatus>(["sent", "cancelled"]);

const EMPTY: ScheduledMessage[] = [];

const HOUR_MS = 3_600_000;
const STANDARD_WINDOW_MS = 24 * HOUR_MS;
const HUMAN_AGENT_WINDOW_MS = 7 * 24 * HOUR_MS;

interface ScheduledState {
  conversationId: number | null;
  items: ScheduledMessage[];
  window: MessagingWindow | null;
}

function byScheduledFor(a: ScheduledMessage, b: ScheduledMessage): number {
  return a.scheduled_for.localeCompare(b.scheduled_for);
}

/**
 * Mensajes programados de la conversación abierta y su ventana de mensajería
 * de Meta. La lista se carga al cambiar de chat y después se mantiene con
 * `upsert` (respuesta de la API o evento `scheduled-message.updated` de
 * Reverb); la ventana se reabre con `noteInbound` cuando el contacto escribe.
 *
 * El estado guarda de qué conversación es: al cambiar de chat se descarta
 * por derivación, sin resetear dentro del effect.
 */
export function useScheduledMessages(conversationId: number | null) {
  const [state, setState] = useState<ScheduledState>({ conversationId: null, items: EMPTY, window: null });

  useEffect(() => {
    if (!conversationId) return;

    let cancelled = false;
    listScheduledMessages(conversationId)
      .then((response) => {
        if (cancelled) return;
        setState({
          conversationId,
          items: response.data.toSorted(byScheduledFor),
          window: response.window,
        });
      })
      .catch(() => {
        // Sin la lista el chat sigue funcionando; la barra queda oculta.
      });

    return () => {
      cancelled = true;
    };
  }, [conversationId]);

  const upsert = useCallback((item: ScheduledMessage) => {
    setState((current) => {
      if (current.conversationId !== item.conversation_id) return current;
      const previous = current.items.find((existing) => existing.id === item.id);
      const rest = current.items.filter((existing) => existing.id !== item.id);
      if (HIDDEN_STATUSES.has(item.status)) return { ...current, items: rest };
      // El evento de Reverb trae `user` sólo si el back lo cargó: conservar el que ya teníamos.
      const merged = { ...item, user: item.user ?? previous?.user };
      return { ...current, items: [...rest, merged].toSorted(byScheduledFor) };
    });
  }, []);

  const remove = useCallback((id: number) => {
    setState((current) => ({ ...current, items: current.items.filter((item) => item.id !== id) }));
  }, []);

  /** Un entrante en tiempo real reabre la ventana sin volver a pedirla al back. */
  const noteInbound = useCallback((message: Message) => {
    setState((current) => {
      if (message.direction !== "inbound" || current.conversationId !== message.conversation_id) return current;
      if (!current.window?.applies) return current;

      const at = Date.parse(message.created_at);
      if (Number.isNaN(at)) return current;

      const expiresAt = new Date(at + STANDARD_WINDOW_MS).toISOString();
      if (current.window.expiresAt && Date.parse(current.window.expiresAt) >= Date.parse(expiresAt)) return current;

      return {
        ...current,
        window: {
          ...current.window,
          expiresAt,
          humanAgentExpiresAt: current.window.humanAgentEnabled
            ? new Date(at + HUMAN_AGENT_WINDOW_MS).toISOString()
            : null,
        },
      };
    });
  }, []);

  const isCurrent = conversationId !== null && state.conversationId === conversationId;

  return {
    items: isCurrent ? state.items : EMPTY,
    window: isCurrent ? state.window : null,
    upsert,
    remove,
    noteInbound,
  };
}
