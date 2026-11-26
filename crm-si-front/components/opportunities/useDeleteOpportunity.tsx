"use client"

import { useState, type ReactNode } from "react"
import { Loader2 } from "lucide-react"
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
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { deleteOpportunity } from "@/lib/api/opportunities"

export interface DeletableOpportunity {
  id: number
  contactName: string
  /** Dónde está: "Ventas · Negociación". */
  summary: string
  /** Ganada: su venta puede haberse informado a Meta y ese registro se borra con ella. */
  won: boolean
}

export interface OpportunityDeletion {
  /** Sin el permiso `opportunities.delete` las pantallas no ofrecen la acción. */
  canDelete: boolean
  requestDelete: (opportunity: DeletableOpportunity) => void
  /** Se renderiza fuera de popovers y menús: sobrevive a que se cierren. */
  dialog: ReactNode
}

/**
 * Confirmación y borrado de una oportunidad, igual en tablero, chats y
 * contactos. El diálogo queda abierto mientras borra; `onDeleted` corre recién
 * con el borrado confirmado por el servidor.
 */
export function useDeleteOpportunity(onDeleted: (opportunityId: number) => void): OpportunityDeletion {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const canDelete = usePermission("opportunities.delete")
  const [target, setTarget] = useState<DeletableOpportunity | null>(null)
  const [deleting, setDeleting] = useState(false)

  const confirm = async () => {
    if (!target) return
    setDeleting(true)
    try {
      await deleteOpportunity(target.id)
      onDeleted(target.id)
      addToast({ type: "success", title: t("pipeline.delete.deleted"), description: `${target.contactName} · ${target.summary}` })
      setTarget(null)
    } catch (error) {
      addToast({ type: "error", title: t("pipeline.delete.error"), description: error instanceof Error ? error.message : undefined })
    } finally {
      setDeleting(false)
    }
  }

  const dialog = (
    <AlertDialog open={target !== null} onOpenChange={(open) => { if (!open && !deleting) setTarget(null) }}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t("pipeline.delete.title")}</AlertDialogTitle>
          <AlertDialogDescription>
            {t("pipeline.delete.description", { name: target?.contactName ?? "", summary: target?.summary ?? "" })}
            {target?.won && <span className="mt-2 block">{t("pipeline.delete.wonWarning")}</span>}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={deleting}>{t("common.cancel")}</AlertDialogCancel>
          <AlertDialogAction
            onClick={(event) => {
              // Radix cerraría antes de que termine el borrado.
              event.preventDefault()
              void confirm()
            }}
            disabled={deleting}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          >
            {deleting && <Loader2 className="size-4 animate-spin" aria-hidden="true" />}
            {t("pipeline.delete.confirm")}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )

  return { canDelete, requestDelete: setTarget, dialog }
}
