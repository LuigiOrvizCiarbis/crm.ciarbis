"use client"

import { Facebook } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import type { MessengerPageOption } from "@/hooks/useMessengerLogin"

interface MessengerPageSelectDialogProps {
  /** Lista de páginas a elegir. Si es null, el diálogo está cerrado. */
  pages: MessengerPageOption[] | null
  onSelect: (pageId: string) => void
  onCancel: () => void
}

/**
 * Diálogo de selección de página de Facebook cuando el usuario administra más de
 * una. Aparece en la segunda vuelta del onboarding de Messenger.
 */
export function MessengerPageSelectDialog({
  pages,
  onSelect,
  onCancel,
}: MessengerPageSelectDialogProps) {
  const open = pages !== null && pages.length > 0

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) onCancel() }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Elegí una página de Facebook</DialogTitle>
          <DialogDescription>
            Administrás varias páginas. Seleccioná cuál querés conectar como
            canal de Messenger.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-2">
          {(pages ?? []).map((page) => (
            <button
              key={page.page_id}
              type="button"
              onClick={() => onSelect(page.page_id)}
              className="flex items-center gap-3 rounded-md border border-border p-3 text-left transition-colors hover:bg-muted/60"
            >
              <Facebook className="h-5 w-5 shrink-0 text-blue-600" />
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-foreground">
                  {page.name || page.page_id}
                </p>
                <p className="truncate text-xs text-muted-foreground">{page.page_id}</p>
              </div>
            </button>
          ))}
        </div>

        <div className="flex justify-end">
          <Button variant="ghost" onClick={onCancel}>
            Cancelar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
