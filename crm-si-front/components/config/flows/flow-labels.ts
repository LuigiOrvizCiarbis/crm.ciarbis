import type { WhatsAppFlowCategory, WhatsAppFlowStatus } from "@/data/types"

export const FLOW_CATEGORIES: WhatsAppFlowCategory[] = [
  "SIGN_UP",
  "SIGN_IN",
  "APPOINTMENT_BOOKING",
  "LEAD_GENERATION",
  "CONTACT_US",
  "CUSTOMER_SUPPORT",
  "SURVEY",
  "OTHER",
]

/** Clases del badge por estado de Meta. */
export const FLOW_STATUS_CLASS: Record<WhatsAppFlowStatus, string> = {
  DRAFT: "border-transparent bg-muted text-muted-foreground",
  PUBLISHED: "border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400",
  DEPRECATED: "border-transparent bg-zinc-500/15 text-zinc-600 dark:text-zinc-400",
  BLOCKED: "border-transparent bg-destructive/15 text-destructive",
  THROTTLED: "border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400",
}

/** Meta permite editar borradores y publicados (estos vuelven a borrador). */
export function isFlowEditable(status: WhatsAppFlowStatus): boolean {
  return status === "DRAFT" || status === "PUBLISHED"
}
