"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { ArrowLeft, Check } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { DataTable, type DataTableColumn } from "@/components/ui/data-table"
import { getPlans, type Plan, type PlanCompareValue } from "@/lib/api/plans"
import { useAuthStore } from "@/store/useAuthStore"
import { trialDaysLeft } from "@/lib/trial"
import { useTranslation } from "@/hooks/useTranslation"

const SALES_MAILTO = "mailto:ventas@socialimpulse.com"

// Orden de filas de la tabla comparativa; claves desconocidas de la DB se
// agregan al final con label humanizado para no romper si se editan los planes.
const COMPARE_KEY_ORDER = ["users", "channel_accounts", "conversations_month", "ai"]

export default function PricingPage() {
  const [plans, setPlans] = useState<Plan[] | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)
  const { user } = useAuthStore()
  const { t, language } = useTranslation()

  const loadPlans = useCallback(() => {
    setLoadFailed(false)
    setPlans(null)
    getPlans()
      .then(setPlans)
      .catch(() => setLoadFailed(true))
  }, [])

  useEffect(() => {
    loadPlans()
  }, [loadPlans])

  // Página pública: sin sesión no hay plan actual. Con sesión, tenant sin plan
  // asignado opera como "free" (mismo default que lib/trial.ts).
  const currentPlanKey = user ? (user.tenant?.plan?.key ?? "free") : null
  const daysLeft = trialDaysLeft(user?.tenant)

  // One Voice Rule: un solo CTA primario por pantalla — el upgrade inmediato
  // al plan actual (o el primer plan pago para visitantes). El resto, outline.
  const currentIndex = currentPlanKey ? (plans?.findIndex((p) => p.key === currentPlanKey) ?? -1) : -1
  const upgradeKey =
    currentIndex >= 0
      ? (plans?.[currentIndex + 1]?.key ?? null)
      : !user
        ? (plans?.find((p) => p.priceMonthly !== 0)?.key ?? null)
        : null

  const featureLabel = (key: string, fallback: string) => {
    const translated = t(`pricing.features.${key}`)
    return translated === `pricing.features.${key}` ? fallback : translated
  }

  const compareLabel = (key: string) => {
    const translated = t(`pricing.compare.${key}`)
    return translated === `pricing.compare.${key}` ? key.replace(/_/g, " ") : translated
  }

  const formatCompareValue = (value: PlanCompareValue | undefined) => {
    if (value === undefined || value === false) return null
    if (value === true) return "check" as const
    if (value === "custom") return t("pricing.customValue")
    if (/^\d+$/.test(value)) {
      return Number(value).toLocaleString(language === "es" ? "es-AR" : "en-US")
    }
    return value
  }

  const compareKeys = plans
    ? [
        ...COMPARE_KEY_ORDER.filter((key) => plans.some((p) => key in p.compare)),
        ...plans
          .flatMap((p) => Object.keys(p.compare))
          .filter((key, i, all) => !COMPARE_KEY_ORDER.includes(key) && all.indexOf(key) === i),
      ]
    : []

  const priceBlock = (plan: Plan) => {
    if (plan.priceMonthly === null) {
      return <span className="text-3xl font-semibold">{t("pricing.customPrice")}</span>
    }
    if (plan.priceMonthly === 0) {
      return <span className="text-4xl font-semibold">{t("pricing.freePrice")}</span>
    }
    return (
      <>
        <span className="text-4xl font-semibold tabular-nums">${plan.priceMonthly}</span>
        <span className="text-muted-foreground"> {t("pricing.perMonth")}</span>
      </>
    )
  }

  return (
    <div className="min-h-screen bg-background">
      <div className="container mx-auto max-w-5xl px-4 py-6">
        <Link href={user ? "/configuracion" : "/"}>
          <Button variant="ghost" size="sm" className="gap-2 -ml-2">
            <ArrowLeft className="w-4 h-4" />
            {user ? t("pricing.back") : t("pricing.backHome")}
          </Button>
        </Link>
      </div>

      <div className="container mx-auto max-w-5xl px-4 pb-16 space-y-12">
        <div className="text-center space-y-3">
          <h1 className="text-3xl font-semibold">{t("pricing.title")}</h1>
          <p className="text-muted-foreground">{t("pricing.subtitle")}</p>
        </div>

        {loadFailed && (
          <div className="text-center space-y-4 py-12" role="alert">
            <p className="text-muted-foreground">{t("pricing.loadError")}</p>
            <Button variant="outline" onClick={loadPlans}>
              {t("pricing.retry")}
            </Button>
          </div>
        )}

        {!loadFailed && !plans && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6" aria-hidden>
            {[0, 1, 2].map((i) => (
              <div key={i} className="rounded-xl border p-6 space-y-4">
                <Skeleton className="h-6 w-24" />
                <Skeleton className="h-10 w-32" />
                <div className="space-y-2 pt-4">
                  <Skeleton className="h-4 w-full" />
                  <Skeleton className="h-4 w-5/6" />
                  <Skeleton className="h-4 w-4/6" />
                </div>
                <Skeleton className="h-9 w-full" />
              </div>
            ))}
          </div>
        )}

        {plans && (
          <>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {plans.map((plan) => {
                const isCurrent = plan.key === currentPlanKey

                return (
                  <Card key={plan.id} className={`relative ${isCurrent ? "ring-2 ring-primary" : ""}`}>
                    {isCurrent && (
                      <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                        <Badge variant="default" className="px-4">
                          {t("pricing.currentPlan")}
                        </Badge>
                      </div>
                    )}
                    <CardHeader>
                      <CardTitle className="text-xl">{plan.name}</CardTitle>
                      <div className="mt-2">{priceBlock(plan)}</div>
                      {isCurrent && daysLeft !== null && (
                        <p className="text-sm text-muted-foreground mt-1">
                          {daysLeft === 1
                            ? t("pricing.trialDaysLeftOne")
                            : t("pricing.trialDaysLeft", { count: daysLeft })}
                        </p>
                      )}
                    </CardHeader>
                    <CardContent className="flex-1 flex flex-col gap-6">
                      <ul className="space-y-2 flex-1">
                        {plan.features.map((feature) => (
                          <li key={feature.key} className="flex items-start gap-2 text-sm">
                            <Check className="w-4 h-4 text-success mt-0.5 shrink-0" aria-hidden />
                            <span>{featureLabel(feature.key, feature.label)}</span>
                          </li>
                        ))}
                      </ul>

                      {isCurrent ? (
                        <Button className="w-full" variant="outline" disabled>
                          {t("pricing.currentPlanCta")}
                        </Button>
                      ) : (
                        <Button className="w-full" variant={plan.key === upgradeKey ? "default" : "outline"} asChild>
                          <a href={`${SALES_MAILTO}?subject=${encodeURIComponent(plan.name)}`}>
                            {t("pricing.contactSales")}
                          </a>
                        </Button>
                      )}
                    </CardContent>
                  </Card>
                )
              })}
            </div>

            {compareKeys.length > 0 && (
              <section className="space-y-4">
                <h2 className="text-xl font-semibold text-center">{t("pricing.comparisonTitle")}</h2>
                <DataTable<string>
                  className="rounded-xl"
                  columns={[
                    {
                      id: "feature",
                      header: t("pricing.comparisonFeature"),
                      rowHeader: true,
                      minWidth: "200px",
                      headerClassName: "min-w-[200px]",
                      cell: (key) => compareLabel(key),
                    },
                    ...plans.map<DataTableColumn<string>>((plan) => ({
                      id: String(plan.id),
                      header: plan.name,
                      align: "center",
                      headerClassName: "min-w-[120px] font-semibold",
                      cell: (key) => {
                        const rendered = formatCompareValue(plan.compare[key])
                        return rendered === "check" ? (
                          <Check className="w-4 h-4 text-success mx-auto" aria-hidden />
                        ) : rendered === null ? (
                          <span className="text-muted-foreground" aria-hidden>
                            —
                          </span>
                        ) : (
                          <span className="tabular-nums">{rendered}</span>
                        )
                      },
                    })),
                  ]}
                  data={compareKeys}
                  getRowId={(key) => key}
                />
              </section>
            )}
          </>
        )}

        <div className="text-center space-y-3 pt-4">
          <h2 className="text-xl font-semibold">{t("pricing.helpTitle")}</h2>
          <p className="text-muted-foreground max-w-md mx-auto">{t("pricing.helpDescription")}</p>
          <Button size="lg" variant="outline" asChild>
            <a href={SALES_MAILTO}>{t("pricing.contactSales")}</a>
          </Button>
        </div>
      </div>
    </div>
  )
}
