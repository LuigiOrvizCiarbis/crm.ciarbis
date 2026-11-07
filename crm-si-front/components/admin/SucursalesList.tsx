"use client"

import { useEffect, useState } from "react"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Pencil, Trash2, Loader2, Building2, MapPin, Phone, Mail } from "lucide-react"
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
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { usePermission } from "@/hooks/usePermission"
import { useBranchesStore } from "@/store/useBranchesStore"
import { Branch } from "@/lib/api/branches"
import { SucursalForm } from "./SucursalForm"

type SucursalesListProps = {
  creating?: boolean
  onCreatingChange?: (open: boolean) => void
}

export function SucursalesList({ creating: creatingProp, onCreatingChange }: SucursalesListProps = {}) {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const canManage = usePermission("branches.manage")
  const { branches, loaded, loading, fetch, remove } = useBranchesStore()

  const [editing, setEditing] = useState<Branch | null>(null)
  const [creatingInternal, setCreatingInternal] = useState(false)
  const creating = creatingProp ?? creatingInternal
  const setCreating = onCreatingChange ?? setCreatingInternal
  const [deletingId, setDeletingId] = useState<number | null>(null)

  useEffect(() => {
    fetch()
  }, [fetch])

  const handleDelete = async (id: number) => {
    const { ok, error } = await remove(id)
    setDeletingId(null)
    if (!ok) {
      addToast({ title: t("common.error"), description: error || "", type: "error" })
      return
    }
    addToast({ type: "success", title: t("sucursales.deleted") })
  }

  if (loading && !loaded) {
    return (
      <div className="flex justify-center p-8">
        <Loader2 className="w-6 h-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {branches.length === 0 ? (
        <div className="border-y border-border">
          <p className="py-4 text-sm text-muted-foreground">{t("sucursales.empty")}</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {branches.map((branch) => (
            <Card key={branch.id}>
              <CardContent className="p-4 space-y-3">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-2">
                    <Building2 className="w-4 h-4 text-muted-foreground" />
                    <span className="font-medium">{branch.name}</span>
                    {!branch.is_active && (
                      <Badge variant="secondary">{t("sucursales.inactive")}</Badge>
                    )}
                  </div>
                  {canManage && (
                    <div className="flex gap-1">
                      <Button size="icon" variant="ghost" onClick={() => setEditing(branch)} aria-label={t("sucursales.editNamed", { name: branch.name })}>
                        <Pencil className="w-4 h-4" aria-hidden="true" />
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="text-muted-foreground hover:text-destructive"
                        onClick={() => setDeletingId(branch.id)}
                        aria-label={t("sucursales.deleteNamed", { name: branch.name })}
                      >
                        <Trash2 className="w-4 h-4" aria-hidden="true" />
                      </Button>
                    </div>
                  )}
                </div>

                <div className="space-y-1 text-sm text-muted-foreground">
                  {branch.address && (
                    <div className="flex items-center gap-2">
                      <MapPin className="w-3 h-3" />
                      <span>{branch.address}</span>
                    </div>
                  )}
                  {branch.phone && (
                    <div className="flex items-center gap-2">
                      <Phone className="w-3 h-3" />
                      <span>{branch.phone}</span>
                    </div>
                  )}
                  {branch.email && (
                    <div className="flex items-center gap-2">
                      <Mail className="w-3 h-3" />
                      <span>{branch.email}</span>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <SucursalForm
        open={creating}
        onOpenChange={setCreating}
        onSaved={() => fetch(true)}
      />
      <SucursalForm
        open={!!editing}
        onOpenChange={(open) => !open && setEditing(null)}
        branch={editing}
        onSaved={() => fetch(true)}
      />

      <AlertDialog open={deletingId !== null} onOpenChange={(open) => !open && setDeletingId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("sucursales.confirmDelete")}</AlertDialogTitle>
            <AlertDialogDescription>{t("sucursales.confirmDeleteDesc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction onClick={() => deletingId && handleDelete(deletingId)}>
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
