"use client"

import { useEffect, useState } from "react"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Loader2, User as UserIcon } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { getUsers, type SystemUser } from "@/lib/api/users"
import { assignUserRole, getRoles, type Role } from "@/lib/api/roles"
import { useAuthStore } from "@/store/useAuthStore"
import { useIsOwner, usePermission } from "@/hooks/usePermission"

export function UsersRoleList() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const currentUser = useAuthStore((s) => s.user)
  const canAssign = usePermission("users.assign_role")
  const isOwner = useIsOwner()

  const [users, setUsers] = useState<SystemUser[]>([])
  const [roles, setRoles] = useState<Role[]>([])
  const [loading, setLoading] = useState(true)
  const [savingUserId, setSavingUserId] = useState<number | null>(null)

  const load = async () => {
    setLoading(true)
    const [u, r] = await Promise.all([getUsers(), getRoles()])
    setUsers(u)
    setRoles(r)
    setLoading(false)
  }

  useEffect(() => {
    load()
  }, [])

  const handleChange = async (userId: number, roleName: string) => {
    setSavingUserId(userId)
    const { error } = await assignUserRole(userId, roleName)
    setSavingUserId(null)

    if (error) {
      addToast({ title: t("roles.assignError"), description: error, type: "error" })
      return
    }
    addToast({ type: "success", title: t("roles.assignSuccess") })
    load()
  }

  return (
    <div>
      {loading ? (
        <div className="divide-y divide-border border-y border-border">
          {[0, 1, 2].map((i) => (
            <div key={i} className="flex items-center gap-3 py-3">
              <Skeleton className="size-5 rounded-full" />
              <div className="flex-1 space-y-1.5">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-3 w-48" />
              </div>
              <Skeleton className="h-9 w-40" />
            </div>
          ))}
        </div>
      ) : users.length === 0 ? (
        <div className="border-y border-border">
          <p className="py-4 text-sm text-muted-foreground">{t("roles.noUsers")}</p>
        </div>
      ) : (
        <div className="divide-y divide-border border-y border-border">
          {users.map((u) => {
            const isCurrent = u.id === currentUser?.id
            const targetIsOwner = u.role?.is_owner === true
            // Only Owner can change another Owner's role.
            const disabled = !canAssign || (targetIsOwner && !isOwner) || isCurrent
            return (
              <div key={u.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="flex items-center gap-3 min-w-0">
                    <UserIcon className="w-5 h-5 text-muted-foreground shrink-0" />
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <p className="font-medium truncate">{u.name}</p>
                        {isCurrent && (
                          <Badge variant="outline" className="text-xs">
                            {t("settings.you")}
                          </Badge>
                        )}
                      </div>
                      <p className="text-xs text-muted-foreground truncate">{u.email}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    {savingUserId === u.id && <Loader2 className="size-4 animate-spin text-muted-foreground" aria-hidden="true" />}
                    <Select
                      value={u.role?.name ?? ""}
                      onValueChange={(v) => handleChange(u.id, v)}
                      disabled={disabled}
                    >
                      <SelectTrigger className="w-40">
                        <SelectValue placeholder="-" />
                      </SelectTrigger>
                      <SelectContent>
                        {roles.map((r) => (
                          <SelectItem key={r.id} value={r.name}>
                            {r.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
