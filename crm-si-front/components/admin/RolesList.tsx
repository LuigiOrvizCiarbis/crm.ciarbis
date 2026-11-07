"use client"

import { useEffect, useState } from "react"
import { Skeleton } from "@/components/ui/skeleton"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Pencil, Trash2, Plus, ShieldCheck, Eye, Crown } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { deleteRole, getRoles, type Role } from "@/lib/api/roles"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import { formatPermission } from "@/lib/permissions"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { RoleEditorSheet } from "./RoleEditorSheet"

export function RolesList() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const canManage = usePermission("roles.manage")
  const isOwner = useIsOwner()

  const [roles, setRoles] = useState<Role[]>([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<Role | null>(null)
  const [creating, setCreating] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  const load = async () => {
    setLoading(true)
    const data = await getRoles()
    setRoles(data)
    setLoading(false)
  }

  useEffect(() => {
    load()
  }, [])

  const handleDelete = async (id: number) => {
    const { error } = await deleteRole(id)
    setDeletingId(null)
    if (error) {
      addToast({ title: t("common.error"), description: error, type: "error" })
      return
    }
    addToast({ type: "success", title: t("roles.deleteSuccess") })
    load()
  }

  const handleSaved = () => {
    setEditing(null)
    setCreating(false)
    load()
  }

  const customRoles = roles.filter((r) => !r.is_system)
  const systemRoles = roles.filter((r) => r.is_system)

  return (
    <div className="space-y-6">
      {loading ? (
        <div className="divide-y divide-border border-y border-border">
          {[0, 1, 2].map((i) => (
            <div key={i} className="flex items-center gap-3 py-3">
              <Skeleton className="size-5 rounded-full" />
              <div className="flex-1 space-y-1.5">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-3 w-48" />
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          {systemRoles.length > 0 && (
            <div className="space-y-2">
              <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                {t("roles.systemBadge")}
              </p>
              <div className="divide-y divide-border border-y border-border">
                {systemRoles.map((role) => (
                  <RoleRow
                    key={role.id}
                    role={role}
                    canEdit={isOwner && !role.is_owner ? true : isOwner}
                    canDelete={false}
                    onEdit={() => setEditing(role)}
                    onDelete={() => setDeletingId(role.id)}
                    t={t}
                  />
                ))}
              </div>
            </div>
          )}

          <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
              <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                {t("roles.customGroup")}
              </p>
              {canManage && (
                <Button onClick={() => setCreating(true)} size="sm">
                  <Plus className="size-4" aria-hidden="true" />
                  {t("roles.newRole")}
                </Button>
              )}
            </div>
            <div className="divide-y divide-border border-y border-border">
              {customRoles.length === 0 ? (
                <p className="py-4 text-sm text-muted-foreground">{t("roles.noRoles")}</p>
              ) : (
                customRoles.map((role) => (
                  <RoleRow
                    key={role.id}
                    role={role}
                    canEdit={canManage}
                    canDelete={canManage}
                    onEdit={() => setEditing(role)}
                    onDelete={() => setDeletingId(role.id)}
                    t={t}
                  />
                ))
              )}
            </div>
          </div>
        </div>
      )}

      <RoleEditorSheet
        open={creating || !!editing}
        role={editing}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
        onSaved={handleSaved}
      />

      <AlertDialog open={deletingId !== null} onOpenChange={(open) => !open && setDeletingId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("roles.deleteRole")}</AlertDialogTitle>
            <AlertDialogDescription>{t("roles.deleteConfirm")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deletingId && handleDelete(deletingId)}
              className="bg-destructive text-white hover:bg-destructive/90"
            >
              {t("roles.deleteRole")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

interface RoleRowProps {
  role: Role
  canEdit: boolean
  canDelete: boolean
  onEdit: () => void
  onDelete: () => void
  t: (key: string, opts?: any) => string
}

function RoleRow({ role, canEdit, canDelete, onEdit, onDelete, t }: RoleRowProps) {
  const isOwnerRole = role.is_owner === true
  return (
    <div className="flex items-center gap-3 py-3">
      {isOwnerRole ? (
        <Crown className="size-5 shrink-0 text-warning" aria-hidden="true" />
      ) : (
        <ShieldCheck className="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
      )}
      <div className="min-w-0 flex-1">
          <div>
            <div className="flex items-center gap-2">
              <p className="font-medium truncate">{role.name}</p>
              {isOwnerRole ? (
                <Badge variant="outline" className="text-xs">
                  {t("roles.ownerBadge")}
                </Badge>
              ) : role.is_system && (
                <Badge variant="outline" className="text-xs">
                  {t("roles.systemBadge")}
                </Badge>
              )}
            </div>
            <p className="text-xs text-muted-foreground">
              {t("roles.permissionsCount", { count: role.permissions.length })}
            </p>
            {role.permissions.length > 0 && (
              <p className="text-xs text-muted-foreground/80 truncate mt-0.5">
                {role.permissions
                  .slice(0, 3)
                  .map((p) => formatPermission(p, t))
                  .join(" · ")}
                {role.permissions.length > 3 ? ` · +${role.permissions.length - 3}` : ""}
              </p>
            )}
          </div>
      </div>
        <div className="flex items-center gap-1 shrink-0">
          {canEdit && (
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-muted-foreground"
              onClick={onEdit}
              title={isOwnerRole ? t("roles.ownerViewOnly") : t("roles.editRole")}
              aria-label={isOwnerRole ? t("roles.viewRoleNamed", { name: role.name }) : t("roles.editRoleNamed", { name: role.name })}
            >
              {isOwnerRole ? <Eye className="size-4" aria-hidden="true" /> : <Pencil className="size-4" aria-hidden="true" />}
            </Button>
          )}
          {canDelete && (
            <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-destructive" onClick={onDelete} aria-label={t("roles.deleteRoleNamed", { name: role.name })}>
              <Trash2 className="size-4" aria-hidden="true" />
            </Button>
          )}
        </div>
    </div>
  )
}
