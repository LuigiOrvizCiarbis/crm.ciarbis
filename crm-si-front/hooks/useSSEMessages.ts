import { useEffect, useRef } from "react";
import { Message } from "@/data/types";
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

interface UseReverbMessagesProps {
  conversationId: string | number | null;
  onMessage: (message: Message) => void;
  onEdited?: (message: Message) => void;
  onDeleted?: (data: { id: number; conversation_id: number }) => void;
  onStatus?: (status: MessageStatusUpdate) => void;
}

export function useSSEMessages({
  conversationId,
  onMessage,
  onEdited,
  onDeleted,
  onStatus,
}: UseReverbMessagesProps) {
  const onMessageRef = useRef(onMessage);
  const onEditedRef = useRef(onEdited);
  const onDeletedRef = useRef(onDeleted);
  const onStatusRef = useRef(onStatus);

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

    return () => {
      channel.unbind_all();
      pusher.unsubscribe(`private-conversations.${conversationId}`);
    };
  }, [conversationId]);
}
