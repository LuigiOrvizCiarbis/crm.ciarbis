"use client"

import { type FormEvent, useState } from "react"
import { Building2, Loader2, TriangleAlert } from "lucide-react"

import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useToast } from "@/components/Toast"
import { clearWorkspaceId } from "@/lib/api/auth-token"
import {
  deactivateWorkspace,
  leaveWorkspace,
  renameWorkspace,
  restoreWorkspace,
} from "@/lib/api/workspaces"
import { useAuthStore } from "@/store/useAuthStore"
import { useTranslation } from "@/hooks/useTranslation"

const MIN_NAME_LENGTH = 2
const MAX_NAME_LENGTH = 100

/** El backend sólo deja renombrar, desactivar y auditar a un Owner. */
function isOwnerRole(role?: string | null): boolean {
  return role?.toLowerCase() === "owner"
}

export function WorkspaceCard() {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const user = useAuthStore((state) => state.user)
  const workspaces = useAuthStore((state) => state.workspaces)
  const storedActiveId = useAuthStore((state) => state.activeWorkspaceId)
  const setWorkspaces = useAuthStore((state) => state.setWorkspaces)
  const updateUser = useAuthStore((state) => state.updateUser)

  const activeId = storedActiveId ?? user?.tenant_id ?? null
  const active = workspaces.find((workspace) => workspace.id === activeId)
  // `workspaces` puede no haber cargado todavía; el tenant del usuario es la
  // fuente de respaldo para el nombre.
  const currentName = active?.name ?? user?.tenant?.name ?? ""
  const isOwner = isOwnerRole(active?.role)
  const isPendingDeletion = active?.status === "pending_deletion"

  const [name, setName] = useState(currentName)
  // El nombre guardado con el que se comparó `name`. Si el store trae otro
  // valor (cambio en otra pestaña, refetch), resincronizamos durante el render
  // en lugar de con un efecto.
  const [syncedName, setSyncedName] = useState(currentName)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [deactivateOpen, setDeactivateOpen] = useState(false)
  const [nameConfirmation, setNameConfirmation] = useState("")
  const [password, setPassword] = useState("")
  const [deactivateError, setDeactivateError] = useState<string | null>(null)
  const [deactivating, setDeactivating] = useState(false)
  const [leaving, setLeaving] = useState(false)
  const [restoring, setRestoring] = useState(false)

  if (currentName !== syncedName) {
    setSyncedName(currentName)
    setName(currentName)
  }

  const trimmed = name.trim()
  const hasChanges = trimmed !== currentName
  const canSubmit = hasChanges && trimmed.length >= MIN_NAME_LENGTH && !saving

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canSubmit) return

    setError(null)
    setSaving(true)
    const result = await renameWorkspace(activeId as number, trimmed)
    setSaving(false)

    if (result.error || !result.data) {
      setError(result.error ?? t("common.error"))
      return
    }

    const saved = result.data.name
    setSyncedName(saved)
    setName(saved)
    setWorkspaces(
      workspaces.map((workspace) =>
        workspace.id === result.data!.id ? { ...workspace, name: saved } : workspace,
      ),
    )
    if (user?.tenant && user.tenant.id === result.data.id) {
      updateUser({ tenant: { ...user.tenant, name: saved } })
    }
    addToast({ type: "success", title: t("settings.workspace.renamed") })
  }

  async function confirmDeactivate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (deactivating) return

    if (nameConfirmation.trim() !== currentName) {
      setDeactivateError(t("settings.workspace.danger.nameMismatch"))
      return
    }

    setDeactivateError(null)
    setDeactivating(true)
    const result = await deactivateWorkspace(activeId as number, nameConfirmation.trim(), password)
    setDeactivating(false)

    if (result.error) {
      setDeactivateError(result.error)
      return
    }

    addToast({ type: "success", title: t("settings.workspace.danger.deactivated") })
    // El workspace activo dejó de serlo: limpiamos la selección de la pestaña
    // y recargamos para que el guard reubique al usuario.
    clearWorkspaceId()
    window.location.assign("/chats")
  }

  async function leave() {
    if (leaving) return
    if (!window.confirm(t("settings.workspace.danger.leaveConfirm", { name: currentName }))) return

    setLeaving(true)
    const result = await leaveWorkspace(activeId as number)
    setLeaving(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    addToast({ type: "success", title: t("settings.workspace.danger.left") })
    clearWorkspaceId()
    window.location.assign("/chats")
  }

  async function restore() {
    if (restoring) return

    setRestoring(true)
    const result = await restoreWorkspace(activeId as number)
    setRestoring(false)

    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    addToast({ type: "success", title: t("settings.workspace.danger.restored") })
    window.location.reload()
  }

  function handleDeactivateOpenChange(open: boolean) {
    if (deactivating) return
    setDeactivateOpen(open)
    if (!open) {
      setNameConfirmation("")
      setPassword("")
      setDeactivateError(null)
    }
  }

  if (!activeId || !currentName) return null

  return (
    <SettingsBlock
      title={t("settings.workspace.title")}
      description={t("settings.workspace.description")}
      icon={Building2}
      measure="prose"
    >
      <form className="space-y-6" onSubmit={save}>
        <div className="space-y-1.5">
          <Label htmlFor="workspace-name">{t("settings.workspace.nameLabel")}</Label>
          <Input
            id="workspace-name"
            value={name}
            disabled={!isOwner || saving}
            maxLength={MAX_NAME_LENGTH}
            onChange={(event) => {
              setName(event.target.value)
              if (error) setError(null)
            }}
            aria-describedby={error ? "workspace-name-error" : "workspace-name-hint"}
            aria-invalid={Boolean(error)}
          />
          {error ? (
            <p id="workspace-name-error" role="alert" className="text-sm text-destructive">
              {error}
            </p>
          ) : (
            <p id="workspace-name-hint" className="text-xs text-muted-foreground">
              {isOwner ? t("settings.workspace.nameHint") : t("settings.workspace.ownerOnly")}
            </p>
          )}
        </div>

        {isOwner ? (
          <div className="flex justify-end">
            <Button type="submit" size="sm" disabled={!canSubmit}>
              {saving ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
              {saving ? t("common.saving") : t("common.save")}
            </Button>
          </div>
        ) : null}
      </form>

      <div className="mt-8 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
        <h4 className="flex items-center gap-2 text-sm font-semibold text-destructive">
          <TriangleAlert className="size-4 shrink-0" aria-hidden />
          {t("settings.workspace.danger.title")}
        </h4>
        <p className="mt-1 text-xs leading-5 text-muted-foreground">
          {t("settings.workspace.danger.description")}
        </p>

        <div className="mt-4 space-y-4">
          <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div className="min-w-0 max-w-[48ch]">
              <p className="text-sm font-medium">{t("settings.workspace.danger.leaveTitle")}</p>
              <p className="text-xs leading-5 text-muted-foreground">
                {t("settings.workspace.danger.leaveDescription")}
              </p>
            </div>
            <Button type="button" size="sm" variant="outline" onClick={leave} disabled={leaving}>
              {leaving ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
              {t("settings.workspace.danger.leaveAction")}
            </Button>
          </div>

          {isOwner && !isPendingDeletion ? (
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-t border-destructive/20 pt-4">
              <div className="min-w-0 max-w-[48ch]">
                <p className="text-sm font-medium">
                  {t("settings.workspace.danger.deactivateTitle")}
                </p>
                <p className="text-xs leading-5 text-muted-foreground">
                  {t("settings.workspace.danger.deactivateDescription")}
                </p>
              </div>
              <Button
                type="button"
                size="sm"
                variant="destructive"
                onClick={() => setDeactivateOpen(true)}
              >
                {t("settings.workspace.danger.deactivateAction")}
              </Button>
            </div>
          ) : null}

          {isOwner && isPendingDeletion ? (
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-t border-destructive/20 pt-4">
              <div className="min-w-0 max-w-[48ch]">
                <p className="text-sm font-medium">
                  {t("settings.workspace.danger.restoreTitle")}
                </p>
                <p className="text-xs leading-5 text-muted-foreground">
                  {t("settings.workspace.danger.restoreDescription")}
                </p>
              </div>
              <Button type="button" size="sm" onClick={restore} disabled={restoring}>
                {restoring ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
                {t("settings.workspace.danger.restoreAction")}
              </Button>
            </div>
          ) : null}
        </div>
      </div>

      <Dialog open={deactivateOpen} onOpenChange={handleDeactivateOpenChange}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {t("settings.workspace.danger.deactivateDialogTitle", { name: currentName })}
            </DialogTitle>
            <DialogDescription>
              {t("settings.workspace.danger.deactivateDialogDescription")}
            </DialogDescription>
          </DialogHeader>

          <form className="space-y-4" onSubmit={confirmDeactivate}>
            <div className="space-y-1.5">
              <Label htmlFor="workspace-name-confirmation">
                {t("settings.workspace.danger.nameConfirmationLabel", { name: currentName })}
              </Label>
              <Input
                autoFocus
                id="workspace-name-confirmation"
                value={nameConfirmation}
                autoComplete="off"
                onChange={(event) => {
                  setNameConfirmation(event.target.value)
                  if (deactivateError) setDeactivateError(null)
                }}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="workspace-password">
                {t("settings.workspace.danger.passwordLabel")}
              </Label>
              <Input
                id="workspace-password"
                type="password"
                value={password}
                autoComplete="current-password"
                onChange={(event) => {
                  setPassword(event.target.value)
                  if (deactivateError) setDeactivateError(null)
                }}
                aria-describedby={deactivateError ? "workspace-deactivate-error" : undefined}
                aria-invalid={Boolean(deactivateError)}
              />
            </div>

            {deactivateError ? (
              <p id="workspace-deactivate-error" role="alert" className="text-sm text-destructive">
                {deactivateError}
              </p>
            ) : null}

            <DialogFooter className="gap-2 sm:gap-2">
              <Button
                type="button"
                variant="ghost"
                onClick={() => handleDeactivateOpenChange(false)}
                disabled={deactivating}
              >
                {t("common.cancel")}
              </Button>
              <Button
                type="submit"
                variant="destructive"
                disabled={deactivating || !nameConfirmation.trim() || !password}
              >
                {deactivating ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
                {t("settings.workspace.danger.deactivateAction")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </SettingsBlock>
  )
}
