"use client"

import { useCallback, useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Loader2, X, Users, Copy, RefreshCw, Trash2, UserMinus, Check, Send } from "lucide-react"
import { cn } from "@/lib/utils"
import {
  getWhatsAppGroup,
  deleteWhatsAppGroup,
  resetGroupInviteLink,
  removeGroupParticipants,
  updateWhatsAppGroup,
  type WhatsAppGroup,
} from "@/lib/api/whatsapp-groups"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { JoinRequestsList } from "./JoinRequestsList"
import { InviteToGroupDialog } from "./InviteToGroupDialog"

interface GroupPanelProps {
  groupId: number
  isOpen: boolean
  onClose: () => void
  onDeleted?: () => void
  className?: string
}

const statusLabel: Record<WhatsAppGroup["status"], { label: string; variant: "default" | "secondary" | "destructive" | "outline" }> = {
  pending: { label: "Creando…", variant: "secondary" },
  active: { label: "Activo", variant: "default" },
  suspended: { label: "Suspendido", variant: "destructive" },
  deleted: { label: "Eliminado", variant: "outline" },
  failed: { label: "Falló", variant: "destructive" },
}

export function GroupPanel({ groupId, isOpen, onClose, onDeleted, className }: GroupPanelProps) {
  const { addToast } = useToast()
  const canManage = usePermission("whatsapp_groups.manage_participants")
  const canDelete = usePermission("whatsapp_groups.delete")
  const canUpdate = usePermission("whatsapp_groups.update")
  const canInvite = usePermission("whatsapp_groups.invite")
  const [inviteOpen, setInviteOpen] = useState(false)

  const [group, setGroup] = useState<WhatsAppGroup | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [isResettingLink, setIsResettingLink] = useState(false)
  const [isEditingSubject, setIsEditingSubject] = useState(false)
  const [subjectDraft, setSubjectDraft] = useState("")
  const [isSavingSubject, setIsSavingSubject] = useState(false)

  const load = useCallback(() => {
    if (!isOpen || !groupId) return
    let cancelled = false
    setIsLoading(true)
    setError(null)
    getWhatsAppGroup(groupId)
      .then((data) => {
        if (!cancelled) setGroup(data)
      })
      .catch(() => {
        if (!cancelled) setError("No se pudo cargar el grupo.")
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [groupId, isOpen])

  useEffect(() => {
    const cleanup = load()
    return cleanup
  }, [load])

  if (!isOpen) return null

  const copyInviteLink = async () => {
    if (!group?.invite_link) return
    await navigator.clipboard.writeText(group.invite_link)
    addToast({ type: "success", title: "Link copiado" })
  }

  const handleResetLink = async () => {
    if (!group) return
    try {
      setIsResettingLink(true)
      await resetGroupInviteLink(group.id)
      load()
      addToast({ type: "success", title: "Link de invitación reseteado" })
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudo resetear el link" })
    } finally {
      setIsResettingLink(false)
    }
  }

  const handleDelete = async () => {
    if (!group) return
    try {
      await deleteWhatsAppGroup(group.id)
      addToast({ type: "success", title: "Grupo eliminado" })
      onDeleted?.()
      onClose()
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudo eliminar el grupo" })
    }
  }

  const handleRemoveParticipant = async (waId: string | null) => {
    if (!group || !waId) return
    try {
      await removeGroupParticipants(group.id, [waId])
      load()
      addToast({ type: "success", title: "Participante eliminado" })
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudo quitar al participante" })
    }
  }

  const startEditingSubject = () => {
    setSubjectDraft(group?.subject ?? "")
    setIsEditingSubject(true)
  }

  const saveSubject = async () => {
    if (!group) return
    const trimmed = subjectDraft.trim()
    if (!trimmed || trimmed === group.subject) {
      setIsEditingSubject(false)
      return
    }
    try {
      setIsSavingSubject(true)
      await updateWhatsAppGroup(group.id, { subject: trimmed })
      setIsEditingSubject(false)
      load()
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudo renombrar el grupo" })
    } finally {
      setIsSavingSubject(false)
    }
  }

  const status = group ? statusLabel[group.status] : null
  const activeParticipants = group?.participants?.filter((p) => p.status === "active") ?? []
  const pendingParticipants = group?.participants?.filter((p) => p.status === "invited") ?? []

  return (
    <div
      className={cn(
        "w-96 border-l border-border bg-card flex flex-col h-full overflow-hidden transition-all duration-300",
        className,
      )}
    >
      <div className="p-4 border-b border-border flex items-center justify-between sticky top-0 bg-card z-10">
        <h2 className="font-semibold text-lg">Grupo de WhatsApp</h2>
        <Button variant="ghost" size="sm" onClick={onClose} className="h-8 w-8 p-0">
          <X className="w-4 h-4" />
        </Button>
      </div>

      <div className="flex-1 overflow-y-auto p-6 space-y-6">
        {isLoading && (
          <div className="flex items-center justify-center py-12 text-muted-foreground">
            <Loader2 className="w-5 h-5 animate-spin" />
          </div>
        )}

        {error && !isLoading && <p className="text-sm text-destructive text-center py-8">{error}</p>}

        {group && !isLoading && (
          <>
            <div className="flex flex-col items-center space-y-3">
              <div className="flex h-20 w-20 items-center justify-center rounded-full bg-muted">
                <Users className="h-9 w-9 text-muted-foreground" />
              </div>
              <div className="w-full text-center space-y-1">
                {isEditingSubject ? (
                  <div className="flex items-center justify-center gap-1">
                    <Input
                      value={subjectDraft}
                      onChange={(e) => setSubjectDraft(e.target.value)}
                      maxLength={128}
                      className="h-8 max-w-[220px] text-center text-sm"
                      disabled={isSavingSubject}
                      autoFocus
                    />
                    <Button size="sm" variant="ghost" className="h-8 w-8 p-0" onClick={() => void saveSubject()} disabled={isSavingSubject}>
                      <Check className="h-4 w-4" />
                    </Button>
                  </div>
                ) : (
                  <button
                    type="button"
                    className="text-xl font-semibold hover:underline disabled:no-underline disabled:cursor-default"
                    onClick={canUpdate ? startEditingSubject : undefined}
                    disabled={!canUpdate}
                  >
                    {group.subject}
                  </button>
                )}
                {status && <Badge variant={status.variant}>{status.label}</Badge>}
              </div>
            </div>

            {group.status === "pending" && (
              <p className="text-sm text-muted-foreground text-center">
                Meta todavía está confirmando la creación del grupo. Esto puede tardar unos minutos.
              </p>
            )}

            {group.status === "failed" && group.error_message && (
              <p className="text-sm text-destructive text-center">{group.error_message}</p>
            )}

            {group.description && (
              <p className="text-sm text-muted-foreground text-center">{group.description}</p>
            )}

            {group.invite_link && (
              <div className="space-y-2">
                <p className="text-xs text-muted-foreground">Link de invitación</p>
                <div className="flex items-center gap-2">
                  <Input value={group.invite_link} readOnly className="text-xs" />
                  <Button size="sm" variant="outline" className="shrink-0" onClick={() => void copyInviteLink()} title="Copiar">
                    <Copy className="h-4 w-4" />
                  </Button>
                  {canUpdate && (
                    <Button
                      size="sm"
                      variant="outline"
                      className="shrink-0"
                      onClick={() => void handleResetLink()}
                      disabled={isResettingLink}
                      title="Resetear link"
                    >
                      {isResettingLink ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                    </Button>
                  )}
                </div>
              </div>
            )}

            <JoinRequestsList groupId={group.id} onResolved={load} />

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">
                  Participantes ({activeParticipants.length}/8)
                </p>
                {canInvite && group.status === "active" && activeParticipants.length + pendingParticipants.length < 8 && (
                  <Button size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs" onClick={() => setInviteOpen(true)}>
                    <Send className="h-3.5 w-3.5" />
                    Invitar
                  </Button>
                )}
              </div>
              <div className="space-y-1">
                {activeParticipants.length === 0 && pendingParticipants.length === 0 && (
                  <p className="text-sm text-muted-foreground">Todavía no hay participantes.</p>
                )}
                {activeParticipants.map((participant) => (
                  <div key={participant.id} className="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 hover:bg-muted/60">
                    <span className="text-sm truncate">{participant.display_name || participant.wa_id}</span>
                    {canManage && (
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-7 w-7 shrink-0 p-0 text-muted-foreground hover:text-destructive"
                        onClick={() => void handleRemoveParticipant(participant.wa_id)}
                        title="Quitar del grupo"
                      >
                        <UserMinus className="h-3.5 w-3.5" />
                      </Button>
                    )}
                  </div>
                ))}
                {pendingParticipants.map((participant) => (
                  <div key={participant.id} className="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 opacity-70">
                    <span className="text-sm truncate">{participant.display_name || participant.wa_id}</span>
                    <Badge variant="outline" className="text-[10px]">Invitado</Badge>
                  </div>
                ))}
              </div>
            </div>

            {canDelete && (
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="outline" className="w-full text-destructive hover:text-destructive">
                    <Trash2 className="mr-2 h-4 w-4" />
                    Eliminar grupo
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>¿Eliminar este grupo?</AlertDialogTitle>
                    <AlertDialogDescription>
                      El grupo se elimina en WhatsApp y la conversación se archiva en el CRM. Esta acción no se puede deshacer.
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                    <AlertDialogAction onClick={() => void handleDelete()}>Eliminar</AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            )}
          </>
        )}
      </div>

      {group && (
        <InviteToGroupDialog
          open={inviteOpen}
          onOpenChange={setInviteOpen}
          groupId={group.id}
          availableSlots={Math.max(0, 8 - activeParticipants.length - pendingParticipants.length)}
          onInvited={load}
        />
      )}
    </div>
  )
}
