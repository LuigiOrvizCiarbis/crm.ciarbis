"use client"

import Link from "next/link"
import { Clock } from "lucide-react"
import { useAuthStore } from "@/store/useAuthStore"
import { trialDaysLeft } from "@/lib/trial"
import { useTranslation } from "@/hooks/useTranslation"

export function TrialBanner() {
  const { isAuthenticated, user } = useAuthStore()
  const { t } = useTranslation()

  const daysLeft = isAuthenticated ? trialDaysLeft(user?.tenant) : null

  if (daysLeft === null) {
    return null
  }

  const label = daysLeft === 1
    ? t("trial.banner.daysLeftOne")
    : t("trial.banner.daysLeft", { count: daysLeft })

  return (
    <div className="bg-info/10 border-b border-info/30 px-4 py-2">
      <div className="flex items-center justify-between gap-4 max-w-7xl mx-auto">
        <div className="flex items-center gap-2">
          <Clock className="w-4 h-4 text-info shrink-0" />
          <p className="text-sm text-info">
            <strong>{label}</strong>
          </p>
        </div>
        <Link href="/pricing">
          <span className="text-sm underline hover:no-underline font-medium text-info">
            {t("trial.banner.cta")}
          </span>
        </Link>
      </div>
    </div>
  )
}
