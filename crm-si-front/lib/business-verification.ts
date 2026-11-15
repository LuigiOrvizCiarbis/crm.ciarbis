import {
  ShieldAlert,
  ShieldBan,
  ShieldCheck,
  ShieldEllipsis,
  ShieldOff,
  ShieldQuestion,
  ShieldX,
  type LucideIcon,
} from "lucide-react"

import type { BusinessVerification } from "@/lib/api/channels"

export type VerificationTone = "success" | "info" | "warning" | "danger" | "neutral"

// Mismo vocabulario de tono que los badges de estado (components/Badges.tsx).
// `seal` rellena el ícono del sidebar: el trazo toma el color del sidebar y el
// símbolo queda calado, como un sello.
export const VERIFICATION_TONE_CLASSES: Record<VerificationTone, { tile: string; pill: string; seal: string }> = {
  success: { tile: "bg-success/10 text-success", pill: "border-success/30 bg-success/10 text-success", seal: "fill-success" },
  info: { tile: "bg-info/10 text-info", pill: "border-info/30 bg-info/10 text-info", seal: "fill-info" },
  warning: { tile: "bg-warning/10 text-warning", pill: "border-warning/30 bg-warning/10 text-warning", seal: "fill-warning" },
  danger: {
    tile: "bg-destructive/10 text-destructive",
    pill: "border-destructive/30 bg-destructive/10 text-destructive",
    seal: "fill-destructive",
  },
  neutral: { tile: "bg-muted text-muted-foreground", pill: "border-border bg-muted text-muted-foreground", seal: "fill-muted-foreground" },
}

export type VerificationStateKey =
  | "verified"
  | "in_review"
  | "needs_submission"
  | "needs_info"
  | "not_verified"
  | "rejected"
  | "expired"
  | "revoked"
  | "ineligible"
  | "reconnect"
  | "permission_missing"
  | "unknown"

export type VerificationCtaKey = "verify" | "review" | "open"

export interface VerificationPresentation {
  state: VerificationStateKey
  tone: VerificationTone
  icon: LucideIcon
  cta?: VerificationCtaKey
  /** Sólo los estados que el admin resuelve en Meta muestran la guía de pasos. */
  showHowTo?: boolean
}

export type VerificationStatusSource = Pick<BusinessVerification, "status" | "raw_verification_status">

/**
 * Traduce la respuesta del backend a lo que ve el usuario. `pending` agrupa tres
 * situaciones distintas en Meta; el valor crudo dice si Meta está revisando o
 * si todavía espera algo del negocio.
 */
export function presentVerification(verification: VerificationStatusSource | null): VerificationPresentation {
  switch (verification?.status) {
    case "verified":
      return { state: "verified", tone: "success", icon: ShieldCheck }
    case "pending":
      if (verification.raw_verification_status === "pending_submission") {
        return { state: "needs_submission", tone: "warning", icon: ShieldAlert, cta: "verify", showHowTo: true }
      }
      if (verification.raw_verification_status === "pending_need_more_info") {
        return { state: "needs_info", tone: "warning", icon: ShieldAlert, cta: "review" }
      }
      return { state: "in_review", tone: "info", icon: ShieldEllipsis, cta: "open" }
    case "not_verified":
      return { state: "not_verified", tone: "warning", icon: ShieldAlert, cta: "verify", showHowTo: true }
    case "failed":
      return { state: "rejected", tone: "danger", icon: ShieldX, cta: "review", showHowTo: true }
    case "expired":
      return { state: "expired", tone: "warning", icon: ShieldAlert, cta: "verify", showHowTo: true }
    // Ni revocado ni no elegible se resuelven con los pasos de verificación:
    // el motivo está en el Centro de seguridad de Meta.
    case "revoked":
      return { state: "revoked", tone: "danger", icon: ShieldX, cta: "review" }
    case "ineligible":
      return { state: "ineligible", tone: "danger", icon: ShieldBan, cta: "open" }
    case "business_id_missing":
    case "token_invalid":
      return { state: "reconnect", tone: "neutral", icon: ShieldOff }
    case "permission_missing":
      return { state: "permission_missing", tone: "neutral", icon: ShieldOff }
    default:
      return { state: "unknown", tone: "neutral", icon: ShieldQuestion }
  }
}
