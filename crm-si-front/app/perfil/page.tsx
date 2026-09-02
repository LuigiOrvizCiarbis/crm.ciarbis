"use client"

import { useMemo } from "react"
import { Shield, SlidersHorizontal, User } from "lucide-react"

import { SidebarLayout } from "@/components/SidebarLayout"
import { SettingsSectionHeading, SettingsTabs, useSettingsNav, type SettingsSection } from "@/components/config/SettingsNav"
import { ProfileAvatarBlock } from "@/components/profile/ProfileAvatarBlock"
import { ProfileDetailsBlock } from "@/components/profile/ProfileDetailsBlock"
import { ProfilePasswordBlock } from "@/components/profile/ProfilePasswordBlock"
import { ProfilePreferencesBlock } from "@/components/profile/ProfilePreferencesBlock"
import { ProfileSessionsBlock } from "@/components/profile/ProfileSessionsBlock"
import { useTranslation } from "@/hooks/useTranslation"

type ProfileSectionId = "account" | "security" | "preferences"

export default function ProfilePage() {
  const { t } = useTranslation()

  const sections = useMemo<SettingsSection<ProfileSectionId>[]>(
    () => [
      {
        id: "account",
        label: t("profile.page.account.title"),
        description: t("profile.page.account.description"),
        icon: User,
        visible: true,
      },
      {
        id: "security",
        label: t("profile.page.security.title"),
        description: t("profile.page.security.description"),
        icon: Shield,
        visible: true,
      },
      {
        id: "preferences",
        label: t("profile.page.preferences.title"),
        description: t("profile.page.preferences.description"),
        icon: SlidersHorizontal,
        visible: true,
      },
    ],
    [t],
  )

  const { scrollContainerRef, activeSection, visibleSections, navigateToSection } = useSettingsNav(sections)

  return (
    <SidebarLayout>
      <div ref={scrollContainerRef} className="flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-[1200px] px-4 pt-6 md:px-8 md:pt-10 xl:px-12">
          <header className="mb-5 md:mb-6">
            <h1 className="flex items-center gap-2.5 text-2xl font-semibold tracking-tight text-foreground">
              <User className="size-5 text-muted-foreground" aria-hidden />
              {t("profile.page.title")}
            </h1>
            <p className="mt-1.5 max-w-[68ch] text-sm leading-6 text-muted-foreground">
              {t("profile.page.description")}
            </p>
          </header>

          <SettingsTabs
            sections={visibleSections}
            activeSection={activeSection}
            onNavigate={navigateToSection}
            className="sticky top-0 z-20 -mx-4 border-b border-border bg-background px-4 md:-mx-8 md:px-8 xl:-mx-12 xl:px-12"
            label={t("profile.page.navigationLabel")}
          />

          <main className="min-w-0 pb-24">
            <SettingsSectionHeading section={sections[0]}>
              <ProfileAvatarBlock />
              <ProfileDetailsBlock />
            </SettingsSectionHeading>

            <SettingsSectionHeading section={sections[1]}>
              <ProfilePasswordBlock />
              <ProfileSessionsBlock />
            </SettingsSectionHeading>

            <SettingsSectionHeading section={sections[2]}>
              <ProfilePreferencesBlock />
            </SettingsSectionHeading>
          </main>
        </div>
      </div>
    </SidebarLayout>
  )
}
