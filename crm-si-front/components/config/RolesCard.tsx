"use client"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Shield } from "lucide-react"
import { RolesList } from "@/components/admin/RolesList"
import { UsersRoleList } from "@/components/admin/UsersRoleList"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useTranslation } from "@/hooks/useTranslation"
import { usePermission } from "@/hooks/usePermission"

export function RolesCard() {
  const { t } = useTranslation()
  const allowed = usePermission(["roles.view", "roles.manage"])

  if (!allowed) return null

  return (
    <SettingsBlock
      title={t("roles.title")}
      description={t("roles.subtitle")}
      icon={Shield}
    >
      <Tabs defaultValue="roles" className="w-full">
        <TabsList>
          <TabsTrigger value="roles">{t("roles.tabs.roles")}</TabsTrigger>
          <TabsTrigger value="users">{t("roles.tabs.users")}</TabsTrigger>
        </TabsList>
        <TabsContent value="roles" className="pt-5">
          <RolesList />
        </TabsContent>
        <TabsContent value="users" className="pt-5">
          <UsersRoleList />
        </TabsContent>
      </Tabs>
    </SettingsBlock>
  )
}
