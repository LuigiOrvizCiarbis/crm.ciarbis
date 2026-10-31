"use client"

import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Building2, Plus } from "lucide-react"
import { SucursalesList } from "@/components/admin/SucursalesList"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { useTranslation } from "@/hooks/useTranslation"
import { usePermission } from "@/hooks/usePermission"

export function SucursalesCard() {
  const { t } = useTranslation()
  const allowed = usePermission(["branches.view_any", "branches.view", "branches.manage"])
  const canManage = usePermission("branches.manage")
  const [creating, setCreating] = useState(false)

  if (!allowed) return null

  return (
    <SettingsBlock
      title={t("sucursales.title")}
      description={t("sucursales.subtitle")}
      icon={Building2}
      action={
        canManage && (
          <Button onClick={() => setCreating(true)} size="sm">
            <Plus className="mr-1 size-4" />
            {t("sucursales.create")}
          </Button>
        )
      }
    >
      <SucursalesList creating={creating} onCreatingChange={setCreating} />
    </SettingsBlock>
  )
}
