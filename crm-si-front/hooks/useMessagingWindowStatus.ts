import { useEffect, useState } from "react";
import type { MessagingWindow } from "@/lib/api/scheduled-messages";

export type MessagingWindowStatus = "not_applicable" | "open" | "human_agent" | "closed";

/** Máximo delay de setTimeout (int32): más allá el navegador lo dispara al instante. */
const MAX_TIMEOUT_MS = 2_147_483_647;

/**
 * Estado actual de la ventana de Meta. Se re-renderiza solo cuando cruza un
 * límite (24 h o 7 días) para que el composer se bloquee sin recargar.
 *
 * Sin datos (cargando o canal sin ventana) es "not_applicable": no bloquea.
 */
export function useMessagingWindowStatus(window: MessagingWindow | null): MessagingWindowStatus {
  const [, setTick] = useState(0);
  const now = Date.now();
  const expiresAt = window?.expiresAt ? Date.parse(window.expiresAt) : null;
  const humanAgentExpiresAt = window?.humanAgentExpiresAt ? Date.parse(window.humanAgentExpiresAt) : null;
  const nextBoundary = [expiresAt, humanAgentExpiresAt]
    .filter((boundary): boundary is number => boundary !== null && boundary > now)
    .reduce<number | null>((earliest, boundary) => (earliest === null || boundary < earliest ? boundary : earliest), null);

  useEffect(() => {
    if (nextBoundary === null) return;
    const timeout = setTimeout(() => setTick((tick) => tick + 1), Math.min(nextBoundary - Date.now(), MAX_TIMEOUT_MS));
    return () => clearTimeout(timeout);
  }, [nextBoundary]);

  if (!window?.applies) return "not_applicable";
  if (expiresAt !== null && now < expiresAt) return "open";
  if (humanAgentExpiresAt !== null && now < humanAgentExpiresAt) return "human_agent";
  return "closed";
}
