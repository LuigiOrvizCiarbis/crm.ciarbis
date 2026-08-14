"use client"

import { useEffect, useMemo, useRef, useState, type ReactNode } from "react"
import type { LucideIcon } from "lucide-react"
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
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"

type SettingsSectionId = "organization" | "operation" | "integrations"

interface SettingsSection {
  id: SettingsSectionId
  label: string
  description: string
  icon: LucideIcon
  visible: boolean
}

export default function ConfiguracionPage() {
  const { t } = useTranslation()
  const scrollContainerRef = useRef<HTMLDivElement>(null)
  const [activeSection, setActiveSection] =
    useState<SettingsSectionId>("organization")
  const hasHydrated = useAuthStore((state) => state._hasHydrated)

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
  const sections = useMemo<SettingsSection[]>(
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

  const visibleSections = useMemo(
    () => sections.filter((section) => section.visible),
    [sections],
  )
  const visibleSectionIds = visibleSections
    .map((section) => section.id)
    .join(",")

  
  const scrollSectionIntoView = (
    id: SettingsSectionId,
    behavior: ScrollBehavior,
  ) => {
    const container = scrollContainerRef.current
    const section = document.getElementById(id)
    if (!container || !section) return

    const top =
      section.getBoundingClientRect().top -
      container.getBoundingClientRect().top +
      container.scrollTop
    container.scrollTo({ top, behavior })
  }

  useEffect(() => {
    if (!hasHydrated) return

    const sectionIds = visibleSectionIds
      .split(",")
      .filter(Boolean) as SettingsSectionId[]

    if (sectionIds.length === 0) return

    // El hash puede traer subruta de detalle (#integrations/woocommerce):
    // el primer segmento identifica la sección.
    const hashSection = window.location.hash
      .slice(1)
      .split("/")[0] as SettingsSectionId
    const initialSection = sectionIds.includes(hashSection)
      ? hashSection
      : sectionIds[0]

    setActiveSection(initialSection)
    const initialScrollFrame = sectionIds.includes(hashSection)
      ? window.requestAnimationFrame(() => {
          scrollSectionIntoView(hashSection, "auto")
        })
      : null

    const elements = sectionIds
      .map((id) => document.getElementById(id))
      .filter((element): element is HTMLElement => element !== null)

    const observer = new IntersectionObserver(
      (entries) => {
        const visibleEntry = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0]

        if (!visibleEntry) return

        const id = visibleEntry.target.id as SettingsSectionId
        setActiveSection(id)
        // No pisar el deep-link de detalle (#integrations/<id>) mientras
        // la sección visible siga siendo la misma.
        const currentHash = window.location.hash.slice(1)
        if (currentHash !== id && !currentHash.startsWith(`${id}/`)) {
          window.history.replaceState(null, "", `#${id}`)
        }
      },
      {
        root: scrollContainerRef.current,
        rootMargin: "-12% 0px -72% 0px",
        threshold: [0, 0.1, 0.5],
      },
    )

    elements.forEach((element) => observer.observe(element))
    return () => {
      if (initialScrollFrame !== null) {
        window.cancelAnimationFrame(initialScrollFrame)
      }
      observer.disconnect()
    }
  }, [hasHydrated, visibleSectionIds])

  const navigateToSection = (id: SettingsSectionId) => {
    setActiveSection(id)
    window.history.replaceState(null, "", `#${id}`)
    const reduceMotion = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches
    scrollSectionIntoView(id, reduceMotion ? "auto" : "smooth")
  }

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

interface SettingsTabsProps {
  sections: SettingsSection[]
  activeSection: SettingsSectionId
  onNavigate: (id: SettingsSectionId) => void
  label: string
  className?: string
}

function SettingsTabs({
  sections,
  activeSection,
  onNavigate,
  label,
  className,
}: SettingsTabsProps) {
  return (
    <nav aria-label={label} className={className}>
      <div className="flex gap-6 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {sections.map((section) => {
          const Icon = section.icon
          const isActive = activeSection === section.id

          return (
            <button
              key={section.id}
              type="button"
              onClick={() => onNavigate(section.id)}
              aria-current={isActive ? "location" : undefined}
              className={cn(
                "flex min-h-12 shrink-0 items-center gap-2 border-b-2 px-1 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
                isActive
                  ? "border-primary text-foreground"
                  : "border-transparent text-muted-foreground hover:border-border hover:text-foreground",
              )}
            >
              <Icon
                className={cn(
                  "size-4",
                  isActive ? "text-primary" : "text-muted-foreground",
                )}
              />
              {section.label}
            </button>
          )
        })}
      </div>
    </nav>
  )
}

interface SettingsSectionHeadingProps {
  section: SettingsSection
  children: ReactNode
}

function SettingsSectionHeading({
  section,
  children,
}: SettingsSectionHeadingProps) {
  const Icon = section.icon

  return (
    <section
      id={section.id}
      aria-labelledby={`${section.id}-title`}
      className="scroll-mt-14 pt-14 first:pt-10"
    >
      {/* Rótulo de sección: mayúsculas y escala pequeña para que no compita
          con el título de cada bloque; la sección se lee como banda, no como
          otro encabezado del mismo peso. */}
      <div className="mb-7">
        <h2
          id={`${section.id}-title`}
          className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground"
        >
          <Icon className="size-3.5" aria-hidden />
          {section.label}
        </h2>
        <p className="mt-2 max-w-[68ch] text-sm leading-6 text-muted-foreground">
          {section.description}
        </p>
      </div>
      <div className="space-y-10">{children}</div>
    </section>
  )
}
