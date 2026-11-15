"use client"

import { CircleCheck, CircleSlash, Clock, TriangleAlert, XCircle } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import type { WhatsAppTemplate } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"

export type TemplateStatusKey = "approved" | "pending" | "rejected" | "other"

const STATUS_KEY: Partial<Record<WhatsAppTemplate["status"], TemplateStatusKey>> = {
  APPROVED: "approved",
  PENDING: "pending",
  IN_APPEAL: "pending",
  REJECTED: "rejected",
}

export function templateStatusKey(status: WhatsAppTemplate["status"]): TemplateStatusKey {
  return STATUS_KEY[status] ?? "other"
}

const STATUS_ICON = { approved: CircleCheck, pending: Clock, rejected: XCircle, other: CircleSlash }

/** Estado de Meta con ícono y texto; `missingParameters` marca una aprobada a la que le faltan variables. */
export function TemplateStatusBadge({
  status,
  missingParameters = false,
  className,
}: {
  status: WhatsAppTemplate["status"]
  missingParameters?: boolean
  className?: string
}) {
  const { t } = useTranslation()
  const key = templateStatusKey(status)
  if (key === "approved" && missingParameters) {
    return (
      <Badge variant="outline" className={cn("font-normal text-amber-700 dark:text-amber-400", className)}>
        <TriangleAlert aria-hidden />
        {t("flows.journey.templateStatus.parameters")}
      </Badge>
    )
  }
  const Icon = STATUS_ICON[key]
  return (
    <Badge variant="outline" className={cn("font-normal", key === "approved" ? "text-foreground" : "text-muted-foreground", className)}>
      <Icon aria-hidden />
      {t(`flows.journey.templateStatus.${key}`)}
    </Badge>
  )
}
