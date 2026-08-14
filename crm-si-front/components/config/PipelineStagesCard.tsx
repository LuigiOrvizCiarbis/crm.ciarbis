"use client"

import { GitBranch } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useTranslation } from "@/hooks/useTranslation"
import { usePermission } from "@/hooks/usePermission"
import { PipelineStagesManager } from "@/components/pipeline-stages-manager"

export function PipelineStagesCard() {
  const { t } = useTranslation()
  const canView = usePermission(["pipeline_stages.view", "pipeline_stages.manage"])

  if (!canView) return null

  return (
    <SettingsBlock
      title={t("pipeline.stages.cardTitle")}
      description={t("pipeline.stages.manageDesc")}
      icon={GitBranch}
      measure="prose"
    >
      <PipelineStagesManager />
    </SettingsBlock>
  )
}
