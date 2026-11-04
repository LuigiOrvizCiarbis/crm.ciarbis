import { useEffect, useRef } from "react";
import { Conversation, Message, MessageReaction, ScheduledMessage } from "@/data/types";
import { getPusher } from "@/lib/pusher";

export interface MessageStatusUpdate {
  id: number;
  conversation_id: number;
  delivered_at?: string | null;
  read_at?: string | null;
  played_at?: string | null;
  failed_at?: string | null;
  error_message?: string | null;
}

export interface MessageReactionUpdate {
  message_id: number;
  conversation_id: number;
  reactions: MessageReaction[];
}

/** Estado completo de la derivación; `ai_autoreply_enabled` sólo viene al crearla. */
export interface HumanHandoffUpdate {
  conversation_id: number;
  assigned_to: number | null;
  human_handoff: Conversation["humanHandoff"] | null;
  ai_autoreply_enabled?: boolean;
}

interface UseReverbMessagesProps {
  conversationId: string | number | null;
  onMessage: (message: Message) => void;
  onEdited?: (message: Message) => void;
  onDeleted?: (data: { id: number; conversation_id: number }) => void;
  onStatus?: (status: MessageStatusUpdate) => void;
  onReaction?: (data: MessageReactionUpdate) => void;
  onScheduledMessage?: (data: ScheduledMessage) => void;
  onHumanHandoff?: (data: HumanHandoffUpdate) => void;
}

export function useSSEMessages({
  conversationId,
  onMessage,
  onEdited,
  onDeleted,
  onStatus,
  onReaction,
  onScheduledMessage,
  onHumanHandoff,
}: UseReverbMessagesProps) {
  const onMessageRef = useRef(onMessage);
  const onEditedRef = useRef(onEdited);
  const onDeletedRef = useRef(onDeleted);
  const onStatusRef = useRef(onStatus);
  const onReactionRef = useRef(onReaction);
  const onScheduledMessageRef = useRef(onScheduledMessage);
  const onHumanHandoffRef = useRef(onHumanHandoff);

  useEffect(() => {
    onMessageRef.current = onMessage;
  }, [onMessage]);

  useEffect(() => {
    onEditedRef.current = onEdited;
  }, [onEdited]);

  useEffect(() => {
    onDeletedRef.current = onDeleted;
  }, [onDeleted]);

  useEffect(() => {
    onStatusRef.current = onStatus;
  }, [onStatus]);

  useEffect(() => {
    onReactionRef.current = onReaction;
  }, [onReaction]);

  useEffect(() => {
    onScheduledMessageRef.current = onScheduledMessage;
  }, [onScheduledMessage]);

  useEffect(() => {
    onHumanHandoffRef.current = onHumanHandoff;
  }, [onHumanHandoff]);

  useEffect(() => {
    if (!conversationId) return;

    const pusher = getPusher();
    const channel = pusher.subscribe(`private-conversations.${conversationId}`);

    channel.bind("message.sent", (data: Message) => {
      onMessageRef.current(data);
    });

    channel.bind("message.edited", (data: Message) => {
      onEditedRef.current?.(data);
    });

    channel.bind("message.deleted", (data: { id: number; conversation_id: number }) => {
      onDeletedRef.current?.(data);
    });

    channel.bind("message.status", (data: MessageStatusUpdate) => {
      onStatusRef.current?.(data);
    });

    channel.bind("message.reaction", (data: MessageReactionUpdate) => {
      onReactionRef.current?.(data);
    });

    channel.bind("scheduled-message.updated", (data: ScheduledMessage) => {
      onScheduledMessageRef.current?.(data);
    });

    channel.bind("human-handoff.updated", (data: HumanHandoffUpdate) => {
      onHumanHandoffRef.current?.(data);
    });

    return () => {
      channel.unbind_all();
      pusher.unsubscribe(`private-conversations.${conversationId}`);
    };
  }, [conversationId]);
}
