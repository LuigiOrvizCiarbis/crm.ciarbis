"use client"

import Link from "next/link"
import type { ReactNode } from "react"
import { formatDistanceToNowStrict } from "date-fns"
import { enUS, es } from "date-fns/locale"
import { ArrowUpRight } from "lucide-react"
import { PanelSection } from "@/components/dashboard/PanelSection"
import { formatMinutes, formatSeconds } from "@/components/dashboard/duration"
import { Skeleton } from "@/components/ui/skeleton"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import type { DashboardAiImpact, DashboardAiOutcomes } from "@/lib/api/dashboard"
import { cn } from "@/lib/utils"

interface AiImpactProps {
  ai?: DashboardAiImpact | null
  isLoading: boolean
  /** Hay un vendedor o un embudo elegido: este bloque no los usa y conviene decirlo. */
  ignoresFilters: boolean
}

type Translate = ReturnType<typeof useTranslation>["t"]

const plural = (t: Translate, key: string, count: number, params: Record<string, string | number> = {}) =>
  t(`${key}_${count === 1 ? "one" : "other"}`, { count: count.toLocaleString("es-AR"), ...params })

/** El mismo redondeo en la frase, la variación y la leyenda, para que los números coincidan. */
const outcomeShares = (outcomes: DashboardAiOutcomes) =>
  shares([outcomes.ai_only, outcomes.with_human, outcomes.handed_off], outcomes.total)

/** Porcentajes enteros que suman 100: se reparte el resto entre las partes con mayor decimal. */
function shares(values: number[], total: number): number[] {
  if (total <= 0) return values.map(() => 0)
  const exact = values.map((value) => (value / total) * 100)
  const result = exact.map(Math.floor)
  const remainder = 100 - result.reduce((sum, value) => sum + value, 0)
  exact
    .map((value, index) => ({ index, fraction: value - Math.floor(value) }))
    .sort((a, b) => b.fraction - a.fraction)
    .slice(0, remainder)
    .forEach(({ index }) => { result[index] += 1 })
  return result
}

const rowLink =
  "-mx-2 block rounded-md px-2 py-2 outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"

/** Cuánto absorbe la IA y dónde falla: la IA (derivaciones) o las automatizaciones. */
export function AiImpact({ ai, isLoading, ignoresFilters }: AiImpactProps) {
  const { t } = useTranslation()
  const loading = isLoading && !ai

  const showAi = Boolean(
    ai && (ai.ai_enabled || ai.conversations.total > 0 || ai.previous_conversations.total > 0 || ai.handoffs.waiting_count > 0),
  )
  const showAutomations = Boolean(ai && (ai.automations_active || ai.automations.succeeded + ai.automations.failed > 0))

  return (
    <PanelSection
      id="panel-ai"
      title={t("dashboard.ai.title")}
      aside={ignoresFilters ? <span className="text-muted-foreground">{t("dashboard.ai.ignoredFilters")}</span> : undefined}
      busy={isLoading}
    >
      {loading || !ai ? (
        <AiImpactSkeleton />
      ) : (
        <div className="space-y-6">
          {showAi ? <AiSummary ai={ai} /> : <SetUpAi />}
          {(showAi || showAutomations) && (
            <div className={cn("grid gap-x-10 gap-y-6 border-t border-border pt-5", showAi && showAutomations && "md:grid-cols-2")}>
              {showAi && <Handoffs handoffs={ai.handoffs} />}
              {showAutomations && <Automations automations={ai.automations} />}
            </div>
          )}
        </div>
      )}
    </PanelSection>
  )
}

function AiSummary({ ai }: { ai: DashboardAiImpact }) {
  const { t } = useTranslation()
  const { conversations: current, previous_conversations: previous, response_time: response } = ai

  return (
    <div>
      <p className="text-lg font-semibold tracking-tight text-balance">
        {current.total > 0 ? (
          <>
            {plural(t, "dashboard.ai.attended", current.total)}{" "}
            {current.ai_only > 0
              ? plural(t, "dashboard.ai.aiOnly", current.ai_only, { rate: outcomeShares(current)[0] })
              : t("dashboard.ai.aiOnlyNone")}
          </>
        ) : t("dashboard.ai.noConversations")}
      </p>
      {current.total > 0 && previous.total > 0 && <RateChange current={current} previous={previous} />}

      {current.total > 0 && <OutcomeBar outcomes={current} />}

      {response.ai_avg_seconds != null && (
        <p className="mt-5 text-sm text-muted-foreground">
          {t("dashboard.ai.responseTime")}{" "}
          <span className="font-semibold text-ai tabular-nums">{t("dashboard.ai.aiValue", { value: formatSeconds(t, response.ai_avg_seconds) })}</span>
          {response.human_avg_seconds != null && (
            <>
              <span aria-hidden="true"> · </span>
              <span className="font-medium text-foreground tabular-nums">
                {t("dashboard.ai.humanValue", { value: formatSeconds(t, response.human_avg_seconds) })}
              </span>
            </>
          )}
        </p>
      )}
    </div>
  )
}

/** Variación de la tasa de resolución en puntos porcentuales: comparar porcentajes de porcentajes confunde. */
function RateChange({ current, previous }: { current: DashboardAiOutcomes; previous: DashboardAiOutcomes }) {
  const { t } = useTranslation()
  const points = outcomeShares(current)[0] - outcomeShares(previous)[0]

  if (points === 0) return <p className="mt-1 text-sm text-muted-foreground">{t("dashboard.efficiency.noChange")}</p>

  return (
    <p className="mt-1 text-sm text-muted-foreground">
      <span className={cn("font-medium tabular-nums", points > 0 ? "text-success" : "text-destructive")}>
        {t("dashboard.ai.points", { value: `${points > 0 ? "+" : ""}${points}` })}
      </span>{" "}
      {t("dashboard.ai.vsPrevious")}
    </p>
  )
}

function OutcomeBar({ outcomes }: { outcomes: DashboardAiOutcomes }) {
  const { t } = useTranslation()
  const segments = [
    { key: "aiOnly", value: outcomes.ai_only, swatch: "bg-ai" },
    { key: "withHuman", value: outcomes.with_human, swatch: "bg-muted-foreground" },
    { key: "handedOff", value: outcomes.handed_off, swatch: "bg-warning" },
  ]
  const segmentShares = outcomeShares(outcomes)

  return (
    <>
      <div
        role="img"
        aria-label={t("dashboard.ai.barLabel", {
          aiOnly: outcomes.ai_only.toLocaleString("es-AR"),
          withHuman: outcomes.with_human.toLocaleString("es-AR"),
          handedOff: outcomes.handed_off.toLocaleString("es-AR"),
        })}
        className="mt-5 flex h-2.5 gap-0.5 overflow-hidden rounded-full"
      >
        {segments
          .filter((segment) => segment.value > 0)
          .map((segment) => (
            <span key={segment.key} className={cn("min-w-1", segment.swatch)} style={{ flexGrow: segment.value, flexBasis: 0 }} />
          ))}
      </div>
      <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
        {segments.map((segment, index) => (
          <div key={segment.key} className="flex items-center gap-2">
            <span aria-hidden="true" className={cn("size-2.5 shrink-0 rounded-full", segment.swatch)} />
            <dt className="text-muted-foreground">{t(`dashboard.ai.outcomes.${segment.key}`)}</dt>
            <dd className="font-medium tabular-nums">
              {segment.value.toLocaleString("es-AR")}
              <span className="font-normal text-muted-foreground"> · {segmentShares[index]}%</span>
            </dd>
          </div>
        ))}
      </dl>
    </>
  )
}

function SetUpAi() {
  const { t } = useTranslation()
  return (
    <p className="text-sm text-muted-foreground">
      {t("dashboard.ai.setUp")}{" "}
      <Link
        href="/configuracion/integraciones#ai-assistant"
        className="inline-flex items-center gap-0.5 rounded-sm font-medium text-ai underline-offset-4 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
      >
        {t("dashboard.ai.setUpLink")}
        <ArrowUpRight className="size-3.5" aria-hidden="true" />
      </Link>
    </p>
  )
}

function ColumnHeading({ title, summary }: { title: string; summary: ReactNode }) {
  return (
    <div>
      <h3 className="text-sm font-semibold">{title}</h3>
      <p className="mt-1 text-sm text-muted-foreground">{summary}</p>
    </div>
  )
}

function useRelativeTime() {
  const { language } = useTranslation()
  const locale = language === "en" ? enUS : es
  return (iso: string) => formatDistanceToNowStrict(new Date(iso), { addSuffix: true, locale })
}

function Handoffs({ handoffs }: { handoffs: DashboardAiImpact["handoffs"] }) {
  const { t } = useTranslation()
  const ago = useRelativeTime()
  const hidden = handoffs.waiting_count - handoffs.waiting.length

  const summary = handoffs.total > 0
    ? [
        plural(t, "dashboard.ai.handoffs.inPeriod", handoffs.total),
        handoffs.avg_minutes_to_attend != null
          ? t("dashboard.ai.handoffs.attend", { value: formatMinutes(t, handoffs.avg_minutes_to_attend) })
          : null,
      ].filter(Boolean).join(" · ")
    : t("dashboard.ai.handoffs.noneInPeriod")

  return (
    <div className="min-w-0">
      <ColumnHeading title={t("dashboard.ai.handoffs.title")} summary={summary} />
      {handoffs.waiting_count === 0 ? (
        // Sin derivaciones en el período el resumen ya lo dice.
        handoffs.total > 0 && <p className="mt-4 text-sm">{t("dashboard.ai.handoffs.noneWaiting")}</p>
      ) : (
        <>
          <p className="mt-4 text-sm font-medium text-warning">{plural(t, "dashboard.ai.handoffs.waiting", handoffs.waiting_count)}</p>
          <ul className="mt-1">
            {handoffs.waiting.map((handoff) => (
              <li key={handoff.id}>
                <Link href={`/chats?chat=${handoff.conversation_id}`} className={rowLink}>
                  <span className="flex items-baseline justify-between gap-3">
                    <span className="truncate text-sm font-medium">{handoff.contact_name || t("dashboard.ai.handoffs.unnamed")}</span>
                    <span className="shrink-0 text-xs text-muted-foreground">{ago(handoff.created_at)}</span>
                  </span>
                  <span className="mt-0.5 block truncate text-sm text-muted-foreground">
                    {handoff.motive || t("dashboard.ai.handoffs.defaultMotive")}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          {hidden > 0 && <p className="mt-1 text-sm text-muted-foreground">{t("dashboard.ai.more", { count: hidden.toLocaleString("es-AR") })}</p>}
        </>
      )}
    </div>
  )
}

function Automations({ automations }: { automations: DashboardAiImpact["automations"] }) {
  const { t } = useTranslation()
  const ago = useRelativeTime()
  const isOwner = useIsOwner()
  const canViewAutomations = usePermission("automations.view")
  const canOpen = isOwner || canViewAutomations
  const ran = automations.succeeded + automations.failed
  const days = automations.limited_to_days

  const summary = ran > 0 ? (
    <>
      {plural(t, "dashboard.ai.automations.succeeded", automations.succeeded)}
      {automations.failed > 0 && (
        <>
          <span aria-hidden="true"> · </span>
          <span className="font-medium text-warning">{plural(t, "dashboard.ai.automations.failed", automations.failed)}</span>
        </>
      )}
      {/* El historial se borra a los N días: el período elegido se acortó y hay que decirlo. */}
      {days != null && (
        <>
          <span aria-hidden="true"> · </span>
          {t("dashboard.ai.automations.lastDays", { days })}
        </>
      )}
    </>
  ) : days != null ? t("dashboard.ai.automations.noneRanLastDays", { days }) : t("dashboard.ai.automations.noneRan")

  return (
    <div className="min-w-0">
      <ColumnHeading title={t("dashboard.ai.automations.title")} summary={summary} />
      {ran > 0 && automations.failed === 0 && (
        <p className="mt-4 text-sm">{t("dashboard.ai.automations.noFailures")}</p>
      )}
      {automations.failing_rules.length > 0 && (
        <ul className="mt-3">
          {automations.failing_rules.map((rule) => {
            const body = (
              <>
                <span className="block truncate text-sm font-medium">{rule.name}</span>
                <span className="mt-0.5 block text-sm text-muted-foreground">
                  {plural(t, "dashboard.ai.automations.failures", rule.failures)}
                  <span aria-hidden="true"> · </span>
                  {t("dashboard.ai.automations.last", { when: ago(rule.last_failed_at) })}
                </span>
              </>
            )
            return (
              <li key={rule.id}>
                {canOpen ? (
                  <Link href="/configuracion/automatizaciones" className={rowLink}>{body}</Link>
                ) : (
                  <div className="py-2">{body}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

function AiImpactSkeleton() {
  return (
    <div className="space-y-6">
      <div>
        <Skeleton className="h-6 w-full max-w-md" />
        <Skeleton className="mt-5 h-2.5 w-full rounded-full" />
        <div className="mt-3 flex flex-wrap gap-6">
          {[0, 1, 2].map((item) => <Skeleton key={item} className="h-4 w-36" />)}
        </div>
      </div>
      <div className="grid gap-x-10 gap-y-6 border-t border-border pt-5 md:grid-cols-2">
        {[0, 1].map((column) => (
          <div key={column} className="space-y-3">
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-4 w-56" />
            <Skeleton className="h-10 w-full" />
          </div>
        ))}
      </div>
    </div>
  )
}
