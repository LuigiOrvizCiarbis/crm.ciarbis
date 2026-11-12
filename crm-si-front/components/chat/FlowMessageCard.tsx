"use client"

import { ClipboardList, TriangleAlert } from "lucide-react"
import type { Message } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

interface FlowMessageCardProps {
  message: Message
  isUser: boolean
}

export function FlowMessageCard({ message, isUser }: FlowMessageCardProps) {
  const { t } = useTranslation()
  const interactive = message.interactive

  if (!interactive) {
    return <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">{message.content}</p>
  }

  const formatValue = (value: unknown): string => {
    if (typeof value === "boolean") return value ? t("chats.flows.yes") : t("chats.flows.no")
    if (value === null || value === undefined) return ""
    if (typeof value === "object") return JSON.stringify(value)
    return String(value)
  }

  if (interactive.kind === "flow") {
    return (
      <div className="space-y-1.5">
        <span className="flex items-center gap-1 text-xs font-medium opacity-75">
          <ClipboardList className="size-3 shrink-0" aria-hidden="true" />
          {t("chats.flows.flowLabel", { name: interactive.flow_name })}
        </span>
        {interactive.header && <p className="text-sm font-semibold">{interactive.header}</p>}
        <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">{interactive.body}</p>
        {interactive.footer && <p className="text-xs opacity-70">{interactive.footer}</p>}
        <span
          aria-disabled="true"
          className={`mt-1 block rounded-full border px-3 py-1 text-center text-sm font-medium ${isUser ? "border-current/30" : "border-border"} opacity-80`}
        >
          {interactive.cta}
        </span>
      </div>
    )
  }

  const entries = Object.entries(interactive.response ?? {})
  const mappingErrors = Object.entries(interactive.mapping_errors ?? {})
  return (
    <div className="space-y-1.5">
      <span className="flex items-center gap-1 text-xs font-medium opacity-75">
        <ClipboardList className="size-3 shrink-0" aria-hidden="true" />
        {t("chats.flows.responseLabel", { name: interactive.flow_name ?? t("chats.flows.defaultName") })}
      </span>
      {entries.length === 0 ? (
        <p className="text-sm opacity-70">{t("chats.flows.emptyResponse")}</p>
      ) : (
        <dl className="space-y-1 text-sm">
          {entries.map(([key, value]) => (
            <div key={key}>
              <dt className="text-xs opacity-70">{key}</dt>
              <dd className="whitespace-pre-wrap [overflow-wrap:anywhere]">{formatValue(value)}</dd>
            </div>
          ))}
        </dl>
      )}
      {mappingErrors.length > 0 ? (
        <div role="note" className="rounded-md border border-amber-500/40 bg-amber-500/10 px-2.5 py-2 text-xs text-amber-900 dark:text-amber-200">
          <p className="flex items-center gap-1 font-medium">
            <TriangleAlert className="size-3 shrink-0" aria-hidden="true" />
            {t("chats.flows.mappingErrorsTitle")}
          </p>
          <ul className="mt-1 space-y-0.5">
            {mappingErrors.map(([key, error]) => (
              <li key={key} className="[overflow-wrap:anywhere]">
                <span className="font-mono">{key}</span>: {error}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  )
}
