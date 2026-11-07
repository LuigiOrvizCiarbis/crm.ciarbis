"use client"

import { useMemo, type ReactNode } from "react"
import {
  BadgeDollarSign,
  Blocks,
  Building2,
  Filter,
  ListPlus,
  MapPin,
  MessageSquareText,
  PanelLeft,
  Tags,
  Target,
  UsersRound,
  Workflow,
  Zap,
  type LucideIcon,
} from "lucide-react"

import { AutomationsSettings } from "@/components/config/AutomationsSettings"
import { BillingTemplatesCard } from "@/components/config/BillingTemplatesCard"
import { BusinessVerificationCard } from "@/components/config/BusinessVerificationCard"
import { ChannelsCard } from "@/components/config/ChannelsCard"
import { DashboardGoalsCard } from "@/components/config/DashboardGoalsCard"
import { FieldsCard } from "@/components/config/FieldsCard"
import { IntegrationsSection } from "@/components/config/integrations/IntegrationsSection"
import { MessageHotkeysCard } from "@/components/config/MessageHotkeysCard"
import { NavigationLabelsCard } from "@/components/config/NavigationLabelsCard"
import { PipelineStagesCard } from "@/components/config/PipelineStagesCard"
import { MembersCard, RolesCard } from "@/components/config/RolesCard"
import { SucursalesCard } from "@/components/config/SucursalesCard"
import { TagsCard } from "@/components/config/TagsCard"
import { TeamInvitationsCard } from "@/components/config/TeamInvitationsCard"
import { WorkspaceCard } from "@/components/config/WorkspaceCard"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"

export const SETTINGS_ROOT = "/configuracion"

export interface SettingsPageDefinition {
  slug: string
  href: string
  icon: LucideIcon
  title: string
  description: string
  /**
   * Páginas con un solo bloque: el bloque es el encabezado de la página.
   * Con varios, la página pone su propio h1 y los bloques bajan a h2.
   */
  multiBlock: boolean
  render: () => ReactNode
}

export interface SettingsGroupDefinition {
  id: string
  title: string
  description: string
  pages: SettingsPageDefinition[]
}

interface PageSpec {
  slug: string
  key: string
  icon: LucideIcon
  visible: boolean
  multiBlock?: boolean
  render: () => ReactNode
}

/**
 * Mapa de /configuracion: grupos y páginas visibles para el usuario actual.
 * Es la única fuente para el índice, la navegación lateral y la ruta de cada
 * página, así un permiso nuevo o una página nueva se agregan en un solo lugar.
 */
export function useSettingsPages(): SettingsGroupDefinition[] {
  const { t } = useTranslation()

  const canViewRoles = usePermission(["roles.view", "roles.manage"])
  const canViewInvitations = usePermission([
    "invitations.view",
    "invitations.create",
    "invitations.revoke",
  ])
  const canViewBranches = usePermission([
    "branches.view_any",
    "branches.view",
    "branches.manage",
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
  const canManageBilling = usePermission("billing.manage")
  const canManageNavigationLabels = usePermission("navigation_labels.manage")
  const canManageTags = usePermission("tags.manage")
  // Mismo criterio que el backend: el dueño o quien ve las métricas de todo el equipo.
  const canViewTeamAnalytics = usePermission("analytics.view_team")
  const isOwner = useIsOwner()
  const canManageGoals = isOwner || canViewTeamAnalytics

  return useMemo(() => {
    const groups: { id: string; pages: PageSpec[] }[] = [
      {
        id: "organization",
        pages: [
          { slug: "workspace", key: "workspace", icon: Building2, visible: true, render: () => <WorkspaceCard /> },
          {
            slug: "equipo",
            key: "team",
            icon: UsersRound,
            visible: canViewRoles || canViewInvitations,
            multiBlock: true,
            render: () => (
              <>
                {canViewRoles && <MembersCard />}
                {canViewInvitations && <TeamInvitationsCard />}
                {canViewRoles && <RolesCard />}
              </>
            ),
          },
          { slug: "sucursales", key: "branches", icon: MapPin, visible: canViewBranches, render: () => <SucursalesCard /> },
          { slug: "menu", key: "menu", icon: PanelLeft, visible: canManageNavigationLabels, render: () => <NavigationLabelsCard /> },
        ],
      },
      {
        id: "sales",
        pages: [
          { slug: "embudos", key: "pipelines", icon: Filter, visible: canViewPipeline, render: () => <PipelineStagesCard /> },
          { slug: "metas", key: "goals", icon: Target, visible: canManageGoals, render: () => <DashboardGoalsCard /> },
          { slug: "campos", key: "fields", icon: ListPlus, visible: canViewFields, render: () => <FieldsCard /> },
          { slug: "etiquetas", key: "tags", icon: Tags, visible: canManageTags, render: () => <TagsCard /> },
        ],
      },
      {
        id: "messaging",
        pages: [
          { slug: "atajos", key: "shortcuts", icon: Zap, visible: true, render: () => <MessageHotkeysCard /> },
          { slug: "automatizaciones", key: "automations", icon: Workflow, visible: canViewAutomations, render: () => <AutomationsSettings /> },
          { slug: "cobranza", key: "billing", icon: BadgeDollarSign, visible: canManageBilling, render: () => <BillingTemplatesCard /> },
        ],
      },
      {
        id: "integrations",
        pages: [
          {
            slug: "canales",
            key: "channels",
            icon: MessageSquareText,
            visible: true,
            multiBlock: true,
            render: () => (
              <>
                <ChannelsCard />
                <BusinessVerificationCard />
              </>
            ),
          },
          { slug: "integraciones", key: "services", icon: Blocks, visible: true, render: () => <IntegrationsSection /> },
        ],
      },
    ]

    return groups
      .map((group) => ({
        id: group.id,
        title: t(`settings.groups.${group.id}.title`),
        description: t(`settings.groups.${group.id}.description`),
        pages: group.pages
          .filter((page) => page.visible)
          .map((page) => ({
            slug: page.slug,
            href: `${SETTINGS_ROOT}/${page.slug}`,
            icon: page.icon,
            title: t(`settings.pages.${page.key}.title`),
            description: t(`settings.pages.${page.key}.description`),
            multiBlock: page.multiBlock ?? false,
            render: page.render,
          })),
      }))
      .filter((group) => group.pages.length > 0)
  }, [
    t,
    canViewRoles,
    canViewInvitations,
    canViewBranches,
    canManageNavigationLabels,
    canViewPipeline,
    canManageGoals,
    canViewFields,
    canManageTags,
    canViewAutomations,
    canManageBilling,
  ])
}
