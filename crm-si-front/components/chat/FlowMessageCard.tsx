"use client"

import { CalendarCheck, ClipboardList, TriangleAlert } from "lucide-react"
import type { Message } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"

/** Datos de la reserva que agrega el endpoint: van aparte de las respuestas. */
const APPOINTMENT_KEYS: Record<string, true> = { appointment_id: true, appointment_start: true, appointment_status: true }

/** `elegi_una_opcion` → `Elegi una opcion`, para formularios sin etiquetas. */
const humanizeKey = (key: string) => key.replace(/[_.]+/g, " ").replace(/^\p{Ll}/u, (c) => c.toUpperCase())

interface FlowMessageCardProps {
  message: Message
  isUser: boolean
}

export function FlowMessageCard({ message, isUser }: FlowMessageCardProps) {
  const { t, language } = useTranslation()
  const interactive = message.interactive

  if (!interactive) {
    return <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">{message.content}</p>
  }

  const formatValue = (value: unknown): string => {
    if (typeof value === "boolean") return value ? t("chats.flows.yes") : t("chats.flows.no")
    if (value === null || value === undefined) return ""
    if (Array.isArray(value)) return value.map((item) => (typeof item === "object" ? JSON.stringify(item) : String(item))).join(", ")
    if (typeof value === "object") return JSON.stringify(value)
    return String(value)
  }

  // Hora de pared del turno: el ISO trae el offset de la agenda y se muestra tal cual, sin pasarlo a la zona del navegador.
  const formatAppointment = (iso: string): string => {
    const wallClock = new Date(`${iso.slice(0, 19)}Z`)
    if (Number.isNaN(wallClock.getTime())) return iso
    return new Intl.DateTimeFormat(language === "en" ? "en-US" : "es-AR", {
      timeZone: "UTC",
      weekday: "short",
      day: "numeric",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    }).format(wallClock)
  }

  if (interactive.kind === "flow") {
    const { progress, session_status: status } = interactive
    const showProgress = progress != null && status !== "completed" && status !== "superseded"
    return (
      <div className="space-y-1.5">
        <span className="flex flex-wrap items-center gap-1 text-xs font-medium opacity-75">
          <ClipboardList className="size-3 shrink-0" aria-hidden="true" />
          {t("chats.flows.flowLabel", { name: interactive.flow_name })}
          {status === "completed" ? (
            <span className="rounded-full border border-current/30 px-1.5 py-px text-[10px] font-semibold">
              {t("chats.flows.completed")}
            </span>
          ) : null}
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
        {status === "superseded" ? <p className="text-xs italic opacity-70">{t("chats.flows.superseded")}</p> : null}
        {showProgress ? (
          <div className="space-y-0.5 text-xs opacity-75">
            <p>
              {t("chats.flows.inProgress", { count: progress.screens_done })}
              {progress.last_screen_title ? ` · ${progress.last_screen_title}` : ""}
            </p>
            {progress.saved_fields.length > 0 ? (
              <p className="[overflow-wrap:anywhere]">
                {t("chats.flows.savedFields", { fields: progress.saved_fields.join(", ") })}
              </p>
            ) : null}
          </div>
        ) : null}
      </div>
    )
  }

  const response = interactive.response ?? {}
  const labels = interactive.labels ?? {}
  const groups = interactive.groups ?? []
  const groupKeys = new Set(groups.map((group) => group.key))
  const answers = Object.entries(response).filter(([key]) => !Object.hasOwn(APPOINTMENT_KEYS, key) && !groupKeys.has(key))
  const appointmentStart = typeof response.appointment_start === "string" ? response.appointment_start : null
  const appointmentStatus = response.appointment_status
  const mappingErrors = Object.values(interactive.mapping_errors ?? {})
  const statusTone = isUser ? "border border-current/30" : appointmentStatus === "confirmed" ? "bg-success/15 text-success" : "bg-warning/15 text-warning"

  return (
    <div className="space-y-2">
      <span className="flex items-center gap-1 text-xs font-medium opacity-75">
        <ClipboardList className="size-3 shrink-0" aria-hidden="true" />
        {t("chats.flows.responseLabel", { name: interactive.flow_name ?? t("chats.flows.defaultName") })}
      </span>
      {answers.length === 0 && groups.length === 0 && appointmentStart === null ? (
        <p className="text-sm opacity-70">{t("chats.flows.emptyResponse")}</p>
      ) : null}
      {answers.length > 0 ? (
        <dl className="space-y-2 text-sm">
          {answers.map(([key, value]) => {
            const text = formatValue(value)
            return (
              <div key={key} className="space-y-0.5">
                <dt className="text-xs opacity-70">{Object.hasOwn(labels, key) ? labels[key] : humanizeKey(key)}</dt>
                <dd className={`whitespace-pre-wrap [overflow-wrap:anywhere] ${text === "" ? "italic opacity-60" : ""}`}>
                  {text === "" ? t("chats.flows.noAnswer") : text}
                </dd>
              </div>
            )
          })}
        </dl>
      ) : null}
      {groups.map((group) => (
        <div key={group.key} className="space-y-1">
          <p className="text-xs opacity-70">{group.label}</p>
          {group.rows.length === 0 ? (
            <p className="text-sm italic opacity-60">{t("chats.flows.groupEmpty")}</p>
          ) : (
            <div className="overflow-x-auto rounded-md border border-current/15">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-current/15 text-xs">
                    {group.columns.map((column) => (
                      <th key={column.key} scope="col" className="px-2 py-1 font-medium opacity-70">
                        {column.label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {group.rows.map((row, index) => (
                    <tr key={index} className="border-b border-current/10 last:border-0">
                      {group.columns.map((column) => (
                        <td key={column.key} className="px-2 py-1 align-top [overflow-wrap:anywhere]">
                          {formatValue(row[column.key])}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ))}
      {appointmentStart !== null ? (
        <dl className={`space-y-0.5 text-sm ${answers.length > 0 ? "border-t border-current/15 pt-2" : ""}`}>
          <dt className="text-xs opacity-70">{t("chats.flows.appointment")}</dt>
          <dd className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <CalendarCheck className="size-4 shrink-0 opacity-75" aria-hidden="true" />
            <span className="font-medium">{formatAppointment(appointmentStart)}</span>
            {appointmentStatus === "confirmed" || appointmentStatus === "pending" ? (
              <span className={`rounded-full px-2 py-px text-xs font-medium ${statusTone}`}>
                {t(appointmentStatus === "confirmed" ? "chats.flows.appointmentConfirmed" : "chats.flows.appointmentPending")}
              </span>
            ) : null}
          </dd>
        </dl>
      ) : null}
      {mappingErrors.length > 0 ? (
        <div role="note" className="rounded-md border border-warning/40 bg-warning/10 px-2.5 py-2 text-xs text-warning">
          <p className="flex items-center gap-1 font-medium">
            <TriangleAlert className="size-3 shrink-0" aria-hidden="true" />
            {t("chats.flows.mappingErrorsTitle")}
          </p>
          <ul className="mt-1 space-y-0.5">
            {mappingErrors.map((error) => (
              <li key={error} className="[overflow-wrap:anywhere]">
                {error}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  )
}
