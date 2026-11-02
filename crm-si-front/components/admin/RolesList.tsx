"use client"

import { useEffect, useState } from "react"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Pencil, Trash2, Plus, Loader2, ShieldCheck, Eye, Crown } from "lucide-react"
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
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold">{t("roles.title")}</h3>
          <p className="text-sm text-muted-foreground">{t("roles.subtitle")}</p>
        </div>
        {canManage && (
          <Button onClick={() => setCreating(true)} size="sm">
            <Plus className="w-4 h-4 mr-1" />
            {t("roles.newRole")}
          </Button>
        )}
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="w-6 h-6 animate-spin text-muted-foreground" />
        </div>
      ) : (
        <div className="space-y-4">
          {systemRoles.length > 0 && (
            <div className="space-y-2">
              <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                {t("roles.systemBadge")}
              </p>
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
          )}

          <div className="space-y-2">
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
              {t("roles.tabs.roles")}
            </p>
            {customRoles.length === 0 ? (
              <Card className="border-dashed">
                <CardContent className="py-8 text-center text-sm text-muted-foreground">
                  {t("roles.noRoles")}
                </CardContent>
              </Card>
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
    <Card className="border-border">
      <CardContent className="flex items-center justify-between py-3">
        <div className="flex items-center gap-3 min-w-0">
          {isOwnerRole ? (
            <Crown className="w-5 h-5 text-amber-500 shrink-0" />
          ) : (
            <ShieldCheck className="w-5 h-5 text-muted-foreground shrink-0" />
          )}
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <p className="font-medium truncate">{role.name}</p>
              {isOwnerRole ? (
                <Badge variant="default" className="text-xs bg-amber-500/15 text-amber-700 hover:bg-amber-500/15 dark:text-amber-400 border border-amber-500/30">
                  {t("roles.ownerBadge")}
                </Badge>
              ) : role.is_system && (
                <Badge variant="secondary" className="text-xs">
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
              className="h-8 w-8"
              onClick={onEdit}
              title={isOwnerRole ? t("roles.ownerViewOnly") : t("roles.editRole")}
              aria-label={isOwnerRole ? t("roles.viewRoleNamed", { name: role.name }) : t("roles.editRoleNamed", { name: role.name })}
            >
              {isOwnerRole ? <Eye className="w-4 h-4" aria-hidden="true" /> : <Pencil className="w-4 h-4" aria-hidden="true" />}
            </Button>
          )}
          {canDelete && (
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={onDelete} aria-label={t("roles.deleteRoleNamed", { name: role.name })}>
              <Trash2 className="w-4 h-4" aria-hidden="true" />
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
