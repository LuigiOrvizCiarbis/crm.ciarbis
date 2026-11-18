import { Megaphone } from "lucide-react"
import type { AdReferral } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"

interface AdReferralBadgeProps {
  referral: AdReferral
  className?: string
  /** Permite ocultar el texto visualmente (queda para lectores de pantalla) cuando falta espacio. */
  labelClassName?: string
}

/** Pill del anuncio de Meta por el que entró la conversación; enlaza al anuncio si Meta mandó la URL. */
export function AdReferralBadge({ referral, className, labelClassName }: AdReferralBadgeProps) {
  const { t } = useTranslation()
  const label = t(referral.source_type === "post" ? "chats.fromPost" : "chats.fromAd")
  const title = referral.headline ?? t("chats.adIdLabel", { id: referral.ad_id ?? "—" })
  const classes = cn(
    "shrink-0 inline-flex items-center gap-1 rounded-full border px-1.5 text-[11px] font-medium leading-4 border-primary/30 bg-primary/10 text-primary",
    className,
  )
  const content = (
    <>
      <Megaphone className="size-3" aria-hidden="true" />
      <span className={labelClassName}>{label}</span>
    </>
  )

  if (referral.source_url) {
    return (
      <a href={referral.source_url} target="_blank" rel="noopener noreferrer" title={title} className={classes}>
        {content}
      </a>
    )
  }

  return (
    <span title={title} className={classes}>
      {content}
    </span>
  )
}
