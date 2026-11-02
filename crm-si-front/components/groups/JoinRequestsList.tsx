"use client"

import { useCallback, useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Loader2, Check, X } from "lucide-react"
import { getGroupJoinRequests, approveGroupJoinRequests, rejectGroupJoinRequests } from "@/lib/api/whatsapp-groups"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"

interface JoinRequestsListProps {
  groupId: number
  onResolved?: () => void
}

export function JoinRequestsList({ groupId, onResolved }: JoinRequestsListProps) {
  const { addToast } = useToast()
  const canManage = usePermission("whatsapp_groups.manage_participants")
  const [requests, setRequests] = useState<Array<{ wa_id: string }>>([])
  const [isLoading, setIsLoading] = useState(false)
  const [processingWaId, setProcessingWaId] = useState<string | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)

  const load = useCallback(() => {
    let cancelled = false
    setIsLoading(true)
    setLoadFailed(false)
    getGroupJoinRequests(groupId)
      .then((data) => {
        if (!cancelled) setRequests(data)
      })
      .catch(() => {
        if (!cancelled) {
          setRequests([])
          setLoadFailed(true)
        }
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [groupId])

  useEffect(() => {
    const cleanup = load()
    return cleanup
  }, [load])

  // Mientras recarga se mantiene la lista anterior para no parpadear.
  if (loadFailed && !isLoading) {
    return (
      <div className="flex items-center justify-between gap-2 rounded-md border border-border bg-muted/30 p-3">
        <p className="text-xs text-destructive">No se pudieron cargar las solicitudes de ingreso.</p>
        <Button size="sm" variant="outline" className="h-7 shrink-0 px-2 text-xs" onClick={() => load()}>
          Reintentar
        </Button>
      </div>
    )
  }

  if (requests.length === 0) return null

  const respond = async (waId: string, approve: boolean) => {
    try {
      setProcessingWaId(waId)
      if (approve) {
        await approveGroupJoinRequests(groupId, [waId])
        addToast({ type: "success", title: "Solicitud aprobada" })
      } else {
        await rejectGroupJoinRequests(groupId, [waId])
        addToast({ type: "success", title: "Solicitud rechazada" })
      }
      load()
      onResolved?.()
    } catch (err) {
      addToast({ type: "error", title: err instanceof Error ? err.message : "No se pudo procesar la solicitud" })
    } finally {
      setProcessingWaId(null)
    }
  }

  return (
    <div className="space-y-2 rounded-md border border-border bg-muted/30 p-3">
      <p className="text-xs font-medium text-muted-foreground">
        Solicitudes de ingreso ({requests.length})
      </p>
      <div className="space-y-1">
        {requests.map((request) => (
          <div key={request.wa_id} className="flex items-center justify-between gap-2">
            <span className="text-sm truncate">{request.wa_id}</span>
            {canManage && (
              <div className="flex shrink-0 items-center gap-1">
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-7 w-7 p-0 text-primary"
                  onClick={() => void respond(request.wa_id, true)}
                  disabled={processingWaId === request.wa_id}
                  title="Aprobar"
                  aria-label={`Aprobar solicitud de ${request.wa_id}`}
                >
                  {processingWaId === request.wa_id ? <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" /> : <Check className="h-3.5 w-3.5" aria-hidden="true" />}
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-7 w-7 p-0 text-destructive"
                  onClick={() => void respond(request.wa_id, false)}
                  disabled={processingWaId === request.wa_id}
                  title="Rechazar"
                  aria-label={`Rechazar solicitud de ${request.wa_id}`}
                >
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
