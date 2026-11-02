"use client"

import { useEffect, useMemo, useState } from "react"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import { Badge } from "@/components/ui/badge"
import { Loader2, Crown, Info } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import {
  createRole,
  getPermissions,
  updateRole,
  type PermissionGroup,
  type Role,
} from "@/lib/api/roles"
import { useIsOwner } from "@/hooks/usePermission"
import { formatPermission, formatPermissionGroup } from "@/lib/permissions"

interface RoleEditorSheetProps {
  open: boolean
  role: Role | null
  onClose: () => void
  onSaved: () => void
}

export function RoleEditorSheet({ open, role, onClose, onSaved }: RoleEditorSheetProps) {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const isOwner = useIsOwner()

  const [groups, setGroups] = useState<PermissionGroup[]>([])
  const [name, setName] = useState("")
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [loadingPerms, setLoadingPerms] = useState(true)
  const [saving, setSaving] = useState(false)

  const isSystem = !!role?.is_system
  const isOwnerRole = role?.is_owner === true
  // The Owner role is immutable for everyone — even an Owner cannot edit it.
  // Non-system roles are editable by users with roles.manage. System roles
  // (other than Owner) are editable only by an Owner.
  const readOnly = isOwnerRole || (isSystem && !isOwner)

  useEffect(() => {
    if (!open) return
    setLoadingPerms(true)
    getPermissions().then((data) => {
      setGroups(data)
      setLoadingPerms(false)
    })
  }, [open])

  useEffect(() => {
    if (role) {
      setName(role.name)
      setSelected(new Set(role.permissions))
    } else {
      setName("")
      setSelected(new Set())
    }
  }, [role, open])

  const togglePerm = (perm: string) => {
    if (readOnly) return
    const next = new Set(selected)
    if (next.has(perm)) {
      next.delete(perm)
    } else {
      next.add(perm)
    }
    setSelected(next)
  }

  const toggleGroup = (items: string[], on: boolean) => {
    if (readOnly) return
    const next = new Set(selected)
    items.forEach((p) => {
      if (on) {
        next.add(p)
      } else {
        next.delete(p)
      }
    })
    setSelected(next)
  }

  const handleSave = async () => {
    if (!name.trim()) return
    setSaving(true)
    const permissions = Array.from(selected)
    const res = role
      ? await updateRole(role.id, { name: name.trim(), permissions })
      : await createRole(name.trim(), permissions)
    setSaving(false)

    if (res.error) {
      addToast({ title: t("roles.saveError"), description: res.error, type: "error" })
      return
    }

    addToast({ type: "success", title: role ? t("roles.saveSuccess") : t("roles.createSuccess") })
    onSaved()
  }

  const totalSelected = selected.size

  return (
    <Sheet open={open} onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="w-full sm:max-w-2xl flex flex-col gap-0 p-0">
        <SheetHeader className="p-6 pb-4 border-b">
          <SheetTitle className="flex items-center gap-2">
            {isOwnerRole && <Crown className="w-5 h-5 text-amber-500" />}
            {role ? t("roles.editRole") : t("roles.createRole")}
            {isOwnerRole ? (
              <Badge variant="default" className="text-xs bg-amber-500/15 text-amber-700 hover:bg-amber-500/15 dark:text-amber-400 border border-amber-500/30">
                {t("roles.ownerBadge")}
              </Badge>
            ) : isSystem && (
              <Badge variant="secondary" className="text-xs">
                {t("roles.systemBadge")}
              </Badge>
            )}
          </SheetTitle>
          <SheetDescription>
            {isOwnerRole
              ? t("roles.ownerViewOnly")
              : readOnly
                ? t("roles.systemReadOnly")
                : t("roles.subtitle")}
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          {isOwnerRole && (
            <div className="flex gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4">
              <Info className="w-5 h-5 text-warning shrink-0 mt-0.5" />
              <div className="space-y-1">
                <p className="text-sm font-medium text-warning">
                  {t("roles.ownerLockedTitle")}
                </p>
                <p className="text-xs text-warning leading-relaxed">
                  {t("roles.ownerLockedDescription")}
                </p>
              </div>
            </div>
          )}
          <div className="space-y-2">
            <Label htmlFor="role-name">{t("roles.roleName")}</Label>
            <Input
              id="role-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t("roles.roleNamePlaceholder")}
              disabled={readOnly || (isSystem && !isOwner)}
            />
          </div>

          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <Label>{t("roles.permissionsLabel")}</Label>
              <span className="text-xs text-muted-foreground">
                {t("roles.permissionsCount", { count: totalSelected })}
              </span>
            </div>

            {loadingPerms ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="w-6 h-6 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <div className="space-y-4">
                {groups.map((group) => (
                  <PermissionGroupBlock
                    key={group.resource}
                    group={group}
                    selected={selected}
                    onToggle={togglePerm}
                    onToggleGroup={(on) => toggleGroup(group.items, on)}
                    readOnly={readOnly}
                    t={t}
                  />
                ))}
              </div>
            )}
          </div>
        </div>

        <div className="border-t p-4 flex items-center justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={saving}>
            {isOwnerRole ? t("common.close") : t("common.cancel")}
          </Button>
          {!isOwnerRole && (
            <Button onClick={handleSave} disabled={saving || readOnly || !name.trim()}>
              {saving ? <Loader2 className="w-4 h-4 animate-spin mr-1" /> : null}
              {t("common.save")}
            </Button>
          )}
        </div>
      </SheetContent>
    </Sheet>
  )
}

interface PermissionGroupBlockProps {
  group: PermissionGroup
  selected: Set<string>
  onToggle: (perm: string) => void
  onToggleGroup: (on: boolean) => void
  readOnly: boolean
  t: (key: string, opts?: any) => string
}

function PermissionGroupBlock({ group, selected, onToggle, onToggleGroup, readOnly, t }: PermissionGroupBlockProps) {
  const checkedCount = useMemo(
    () => group.items.filter((p) => selected.has(p)).length,
    [group.items, selected],
  )
  const allChecked = checkedCount === group.items.length
  const someChecked = checkedCount > 0 && !allChecked

  return (
    <div className="rounded-lg border border-border">
      <div className="flex items-center justify-between p-3 bg-muted/30">
        <div className="flex items-center gap-2">
          <Checkbox
            checked={allChecked ? true : someChecked ? "indeterminate" : false}
            onCheckedChange={(v) => onToggleGroup(v === true)}
            disabled={readOnly}
          />
          <span className="text-sm font-medium">{formatPermissionGroup(group.resource, t)}</span>
        </div>
        <span className="text-xs text-muted-foreground">
          {checkedCount}/{group.items.length}
        </span>
      </div>
      <div className="p-3 space-y-2">
        {group.items.map((perm) => (
          <label
            key={perm}
            className="flex items-center gap-2 text-sm cursor-pointer hover:bg-muted/40 rounded px-2 py-1"
          >
            <Checkbox
              checked={selected.has(perm)}
              onCheckedChange={() => onToggle(perm)}
              disabled={readOnly}
            />
            <span className="flex-1">{formatPermission(perm, t)}</span>
            <code className="text-[10px] text-muted-foreground/70 font-mono">{perm}</code>
          </label>
        ))}
      </div>
    </div>
  )
}
