import { useCallback, useEffect, useState } from "react";
import type { ScheduledMessage, ScheduledMessageStatus } from "@/data/types";
import { listScheduledMessages } from "@/lib/api/scheduled-messages";

/** Estados que dejan de mostrarse en la barra: ya salió o se descartó. */
const HIDDEN_STATUSES = new Set<ScheduledMessageStatus>(["sent", "cancelled"]);

const EMPTY: ScheduledMessage[] = [];

interface ScheduledState {
  conversationId: number | null;
  items: ScheduledMessage[];
  windowExpiresAt: string | null;
}

function byScheduledFor(a: ScheduledMessage, b: ScheduledMessage): number {
  return a.scheduled_for.localeCompare(b.scheduled_for);
}

/**
 * Mensajes programados de la conversación abierta. La lista se carga al
 * cambiar de chat y después se mantiene con `upsert` (respuesta de la API o
 * evento `scheduled-message.updated` de Reverb).
 *
 * El estado guarda de qué conversación es: al cambiar de chat se descarta
 * por derivación, sin resetear dentro del effect.
 */
export function useScheduledMessages(conversationId: number | null) {
  const [state, setState] = useState<ScheduledState>({ conversationId: null, items: EMPTY, windowExpiresAt: null });

  useEffect(() => {
    if (!conversationId) return;

    let cancelled = false;
    listScheduledMessages(conversationId)
      .then((response) => {
        if (cancelled) return;
        setState({
          conversationId,
          items: response.data.toSorted(byScheduledFor),
          windowExpiresAt: response.windowExpiresAt,
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

  const isCurrent = conversationId !== null && state.conversationId === conversationId;

  return {
    items: isCurrent ? state.items : EMPTY,
    windowExpiresAt: isCurrent ? state.windowExpiresAt : null,
    upsert,
    remove,
  };
}
