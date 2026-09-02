"use client"

import { useMemo } from "react"
import {
  Building2,
  Cable,
  Settings,
  SlidersHorizontal,
} from "lucide-react"

import { SidebarLayout } from "@/components/SidebarLayout"
import { FieldsCard } from "@/components/config/FieldsCard"
import { MessageHotkeysCard } from "@/components/config/MessageHotkeysCard"
import { PipelineStagesCard } from "@/components/config/PipelineStagesCard"
import { RolesCard } from "@/components/config/RolesCard"
import { SucursalesCard } from "@/components/config/SucursalesCard"
import { TeamInvitationsCard } from "@/components/config/TeamInvitationsCard"
import { AutomationsSettings } from "@/components/config/AutomationsSettings"
import { IntegrationsSection } from "@/components/config/integrations/IntegrationsSection"
import { BusinessVerificationCard } from "@/components/config/BusinessVerificationCard"
import { ChannelsCard } from "@/components/config/ChannelsCard"
import { NavigationLabelsCard } from "@/components/config/NavigationLabelsCard"
import { SettingsSectionHeading, SettingsTabs, useSettingsNav, type SettingsSection } from "@/components/config/SettingsNav"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"

type SettingsSectionId = "organization" | "operation" | "integrations"

export default function ConfiguracionPage() {
  const { t } = useTranslation()

  const canViewRoles = usePermission(["roles.view", "roles.manage"])
  const canViewBranches = usePermission([
    "branches.view_any",
    "branches.view",
    "branches.manage",
  ])
  const canViewInvitations = usePermission([
    "invitations.view",
    "invitations.create",
    "invitations.revoke",
  ])
  const canViewFields = usePermission([
    "contact_fields.view",
    "contact_fields.manage",
    "product_fields.view",
    "product_fields.manage",
  ])
  const canViewPipeline = usePermission([
    "pipeline_stages.view",
    "pipeline_stages.manage",
  ])
  const canViewAutomations = usePermission("automations.view")
  const canManageNavigationLabels = usePermission("navigation_labels.manage")
  const sections = useMemo<SettingsSection<SettingsSectionId>[]>(
    () => [
      {
        id: "organization",
        label: t("settings.page.organization.title"),
        description: t("settings.page.organization.description"),
        icon: Building2,
        visible: canViewRoles || canViewBranches || canViewInvitations,
      },
      {
        id: "operation",
        label: t("settings.page.operation.title"),
        description: t("settings.page.operation.description"),
        icon: SlidersHorizontal,
        visible: true,
      },
      {
        id: "integrations",
        label: t("settings.page.integrations.title"),
        description: t("settings.page.integrations.description"),
        icon: Cable,
        visible: true,
      },
    ],
    [
      canViewBranches,
      canViewInvitations,
      canViewRoles,
      t,
    ],
  )

  const { scrollContainerRef, activeSection, visibleSections, navigateToSection } = useSettingsNav(sections)

  return (
    <SidebarLayout>
      <div ref={scrollContainerRef} className="flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-[1200px] px-4 pt-6 md:px-8 md:pt-10 xl:px-12">
          <header className="mb-5 md:mb-6">
            <h1 className="flex items-center gap-2.5 text-2xl font-semibold tracking-tight text-foreground">
              <Settings className="size-5 text-muted-foreground" aria-hidden />
              {t("settings.title")}
            </h1>
            <p className="mt-1.5 max-w-[68ch] text-sm leading-6 text-muted-foreground">
              {t("settings.page.description")}
            </p>
          </header>

          <SettingsTabs
            sections={visibleSections}
            activeSection={activeSection}
            onNavigate={navigateToSection}
            className="sticky top-0 z-20 -mx-4 border-b border-border bg-background px-4 md:-mx-8 md:px-8 xl:-mx-12 xl:px-12"
            label={t("settings.page.navigationLabel")}
          />

          <main className="min-w-0 pb-24">
            {visibleSections.length === 0 ? (
              <div className="border-b border-border py-16">
                <h2 className="text-lg font-semibold">
                  {t("settings.page.restricted.title")}
                </h2>
                <p className="mt-2 max-w-lg text-sm text-muted-foreground">
                  {t("settings.page.restricted.description")}
                </p>
              </div>
            ) : (
              <>
                {(canViewRoles ||
                  canViewBranches ||
                  canViewInvitations) && (
                  <SettingsSectionHeading section={sections[0]}>
                    {canViewRoles && <RolesCard />}
                    {canViewBranches && <SucursalesCard />}
                    {canViewInvitations && <TeamInvitationsCard />}
                  </SettingsSectionHeading>
                )}

                <SettingsSectionHeading section={sections[1]}>
                  {canManageNavigationLabels && <NavigationLabelsCard />}
                  <MessageHotkeysCard />
                  {canViewPipeline && <PipelineStagesCard />}
                  {canViewFields && <FieldsCard />}
                  {canViewAutomations && <AutomationsSettings />}
                </SettingsSectionHeading>

                <SettingsSectionHeading section={sections[2]}>
                  <ChannelsCard />
                  <BusinessVerificationCard />
                  <IntegrationsSection />
                </SettingsSectionHeading>
              </>
            )}
          </main>
        </div>
      </div>
    </SidebarLayout>
  )
}
