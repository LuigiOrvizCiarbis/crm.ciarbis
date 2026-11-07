"use client"

import { Shield, Users } from "lucide-react"
import { RolesList } from "@/components/admin/RolesList"
import { UsersRoleList } from "@/components/admin/UsersRoleList"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useTranslation } from "@/hooks/useTranslation"

/** Quién está en el workspace y con qué rol. */
export function MembersCard() {
  const { t } = useTranslation()

  return (
    <SettingsBlock
      title={t("roles.membersTitle")}
      description={t("roles.assignSubtitle")}
      icon={Users}
    >
      <UsersRoleList />
    </SettingsBlock>
  )
}

/** Qué puede hacer cada rol. */
export function RolesCard() {
  const { t } = useTranslation()

  return (
    <SettingsBlock title={t("roles.blockTitle")} description={t("roles.subtitle")} icon={Shield}>
      <RolesList />
    </SettingsBlock>
  )
}
