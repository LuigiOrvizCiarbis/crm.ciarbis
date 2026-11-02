"use client"

import { useEffect, useMemo, useState } from "react"
import { Loader2, Search, User as UserIcon } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/Toast"
import { bulkAssignConversations } from "@/lib/api/conversations"
import { getUsers, type SystemUser } from "@/lib/api/users"
import { cn } from "@/lib/utils"

interface BulkAssignConversationsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  selectedIds: number[]
  onSuccess: () => void
}

export function BulkAssignConversationsDialog({
  open,
  onOpenChange,
  selectedIds,
  onSuccess,
}: BulkAssignConversationsDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [users, setUsers] = useState<SystemUser[]>([])
  const [loadingUsers, setLoadingUsers] = useState(false)
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null)
  const [query, setQuery] = useState("")
  const [submitting, setSubmitting] = useState(false)

  const count = selectedIds.length

  useEffect(() => {
    if (!open) return
    setSelectedUserId(null)
    setQuery("")
  }, [open])

  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    if (!open) return
    let cancelled = false
    setLoadingUsers(true)
    setLoadFailed(false)
    getUsers()
      .then((loaded) => {
        if (!cancelled) setUsers(loaded)
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })
      .finally(() => {
        if (!cancelled) setLoadingUsers(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, reloadKey])

  const filteredUsers = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return users
    return users.filter(
      (u) =>
        u.name.toLowerCase().includes(q) ||
        (u.email ?? "").toLowerCase().includes(q),
    )
  }, [users, query])

  const canApply = !submitting && count > 0 && selectedUserId !== null

  const handleApply = async () => {
    if (!canApply || selectedUserId === null) return
    setSubmitting(true)
    try {
      const result = await bulkAssignConversations({
        ids: selectedIds,
        user_id: selectedUserId,
      })

      if (result.failed > 0) {
        addToast({
          type: "info",
          title: t("chats.bulk.result.partial", {
            updated: result.updated,
            failed: result.failed,
          }),
        })
      } else {
        addToast({
          type: "success",
          title: t("chats.bulk.result.success", { updated: result.updated }),
        })
      }

      onOpenChange(false)
      onSuccess()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.bulk.errors.apply"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("chats.bulk.dialog.assignTitle")}</DialogTitle>
          <DialogDescription>
            {t("chats.bulk.dialog.subtitle", { count })}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <Label className="text-xs uppercase tracking-wide text-muted-foreground">
            {t("chats.bulk.dialog.userLabel")}
          </Label>
          <div className="relative">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" aria-hidden="true" />
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t("chats.bulk.dialog.searchUsers")}
              aria-label={t("chats.bulk.dialog.searchUsers")}
              className="pl-8"
            />
          </div>
          <div className="max-h-72 overflow-y-auto rounded-md border">
            {loadingUsers ? (
              <div role="status" className="flex items-center gap-2 px-3 py-6 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                {t("chats.bulk.dialog.loadingUsers")}
              </div>
            ) : loadFailed ? (
              <div className="flex flex-col items-center gap-3 px-3 py-6 text-center text-sm text-muted-foreground">
                <p>{t("chats.usersLoadError")}</p>
                <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
                  {t("chats.retry")}
                </Button>
              </div>
            ) : filteredUsers.length === 0 ? (
              <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                {t("chats.bulk.dialog.noUsers")}
              </div>
            ) : (
              filteredUsers.map((u) => {
                const selected = selectedUserId === u.id
                return (
                  <button
                    key={u.id}
                    type="button"
                    onClick={() => setSelectedUserId(u.id)}
                    aria-pressed={selected}
                    className={cn(
                      "flex w-full items-center gap-3 px-3 py-2 text-left text-sm outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-inset focus-visible:ring-ring/50",
                      selected && "bg-primary/10",
                    )}
                  >
                    <UserIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium">{u.name}</p>
                      {u.email && (
                        <p className="truncate text-xs text-muted-foreground">{u.email}</p>
                      )}
                    </div>
                    {selected && (
                      <span className="ml-2 h-2 w-2 rounded-full bg-primary" aria-hidden />
                    )}
                  </button>
                )
              })
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            {t("chats.bulk.dialog.cancel")}
          </Button>
          <Button onClick={handleApply} disabled={!canApply}>
            {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t("chats.bulk.dialog.apply")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
