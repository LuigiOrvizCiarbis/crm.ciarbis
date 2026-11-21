"use client"

import Link from "next/link"
import { getToolName, isToolUIPart, type UIMessage } from "ai"
import { Check, CircleSlash, ClipboardCheck } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useTranslation } from "@/hooks/useTranslation"

/** Herramientas que modifican datos: piden confirmación y se muestran como tarjeta. */
const ACTION_TOOLS: Record<string, { link: string }> = {
  create_task: { link: "/tareas" },
}

type ToolPart = Extract<UIMessage["parts"][number], { toolCallId: string }>

export type ApprovalHandler = (approvalId: string, approved: boolean) => void

/** Partes de herramientas de acción del mensaje, en orden. */
export function actionParts(message: UIMessage): ToolPart[] {
  return message.parts.filter(
    (part): part is ToolPart => isToolUIPart(part) && getToolName(part) in ACTION_TOOLS,
  )
}

/** El mensaje espera que el usuario confirme o cancele una acción. */
export function hasPendingApproval(message: UIMessage): boolean {
  return actionParts(message).some((part) => part.state === "approval-requested")
}

/**
 * El texto a confirmar lo arma el servidor. En el stream llega como
 * approval.requestReason; al recargar el hilo, como approval.reason.
 */
function approvalText(part: ToolPart): string | null {
  const approval: unknown = "approval" in part ? part.approval : null
  if (!approval || typeof approval !== "object") return null
  const fromStream = "requestReason" in approval ? approval.requestReason : null
  const fromHistory = "reason" in approval ? approval.reason : null
  for (const value of [fromStream, fromHistory]) {
    if (typeof value === "string" && value.trim()) return value
  }
  return null
}

function wasCreated(output: unknown): boolean {
  if (typeof output !== "string") return false
  try {
    const parsed: unknown = JSON.parse(output)
    return typeof parsed === "object" && parsed !== null && "created" in parsed && parsed.created === true
  } catch {
    return false
  }
}

export function AssistantActionCard({ part, onDecide }: { part: ToolPart; onDecide?: ApprovalHandler }) {
  const { t } = useTranslation()
  const summary = approvalText(part)
  const tool = ACTION_TOOLS[getToolName(part)]

  if (part.state === "approval-requested") {
    const approvalId = part.approval.id
    return (
      <div className="bg-card w-full max-w-[90%] rounded-xl border p-3 text-sm" role="group" aria-label={t("assistant.approval.title")}>
        <p className="text-muted-foreground mb-1 text-xs font-medium">{t("assistant.approval.title")}</p>
        <p className="mb-3">{summary}</p>
        <div className="flex gap-2">
          <Button type="button" size="sm" disabled={!onDecide} onClick={() => onDecide?.(approvalId, true)}>
            {t("assistant.approval.confirm")}
          </Button>
          <Button type="button" size="sm" variant="outline" disabled={!onDecide} onClick={() => onDecide?.(approvalId, false)}>
            {t("assistant.approval.cancel")}
          </Button>
        </div>
      </div>
    )
  }

  if (part.state === "output-denied" || (part.state === "approval-responded" && part.approval.approved === false)) {
    return (
      <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
        <CircleSlash className="size-3.5" aria-hidden="true" />
        {t("assistant.approval.cancelled")}
      </p>
    )
  }

  if (part.state === "approval-responded") {
    return <p className="text-muted-foreground text-xs">{t("assistant.approval.running")}</p>
  }

  if (part.state === "output-available" && wasCreated(part.output)) {
    return (
      <p className="flex items-center gap-1.5 text-xs">
        <Check className="text-primary size-3.5" aria-hidden="true" />
        {t("assistant.approval.taskCreated")}
        {tool ? (
          <Link href={tool.link} className="text-primary inline-flex items-center gap-1 font-medium underline">
            <ClipboardCheck className="size-3.5" aria-hidden="true" />
            {t("assistant.approval.viewTasks")}
          </Link>
        ) : null}
      </p>
    )
  }

  return null
}
