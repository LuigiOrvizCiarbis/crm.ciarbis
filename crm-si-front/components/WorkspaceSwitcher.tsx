"use client"

import { type FormEvent, useEffect, useMemo, useState } from "react"
import { Check, ChevronsUpDown, Loader2, Plus } from "lucide-react"
import { Button } from "@/components/ui/button"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"
import { getWorkspaceId, setWorkspaceId } from "@/lib/api/auth-token"
import { createWorkspace, getWorkspaces } from "@/lib/api/workspaces"

export function WorkspaceSwitcher({ collapsed = false }: { collapsed?: boolean }) {
  const { user, workspaces, setWorkspaces, setActiveWorkspace } = useAuthStore()
  const storedActiveId = useAuthStore((state) => state.activeWorkspaceId)
  const [loading, setLoading] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [workspaceName, setWorkspaceName] = useState("")
  const [createError, setCreateError] = useState<string | null>(null)
  const storedTabId = getWorkspaceId()
  const activeId = storedTabId ?? storedActiveId ?? user?.tenant_id ?? null
  const active = useMemo(() => workspaces.find((workspace) => workspace.id === activeId), [workspaces, activeId])

  useEffect(() => {
    if (!workspaces.length) void getWorkspaces().then(setWorkspaces)
  }, [setWorkspaces, workspaces.length])

  function select(id: number) {
    if (id === activeId) return
    setLoading(true)
    setWorkspaceId(id)
    setActiveWorkspace(id)
    window.location.assign("/chats")
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (loading) return

    const name = workspaceName.trim()

    if (name.length < 2) {
      setCreateError("Escribí al menos 2 caracteres para continuar.")
      return
    }

    setCreateError(null)
    setLoading(true)
    try {
      const result = await createWorkspace(name)
      if (result.data?.id) {
        setWorkspaces([...workspaces, result.data])
        setActiveWorkspace(result.data.id)
        window.location.assign("/chats")
        return
      }

      setCreateError(result.error ?? "No se pudo crear el workspace.")
    } catch {
      setCreateError("No pudimos conectarnos para crear el workspace. Intentá de nuevo.")
    } finally {
      setLoading(false)
    }
  }

  function handleCreateOpenChange(open: boolean) {
    if (loading) return
    setCreateOpen(open)
    if (!open) {
      setWorkspaceName("")
      setCreateError(null)
    }
  }

  if (!active && !user?.tenant?.name) return null

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" disabled={loading} className={cn("h-auto w-full justify-between gap-2 rounded-xl px-3 py-2 text-left hover:bg-sidebar-accent", collapsed && "justify-center px-2")} aria-label="Cambiar workspace">
          <span className="min-w-0"><span className="block truncate text-sm font-semibold">{active?.name ?? user?.tenant?.name}</span>{!collapsed && <span className="block truncate text-[11px] text-muted-foreground">{active?.role ?? "Workspace activo"}</span>}</span>
          {!collapsed && <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-64">
        <DropdownMenuLabel>Tus workspaces</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {workspaces.map((workspace) => (
          <DropdownMenuItem key={workspace.id} onSelect={() => select(workspace.id)} className="gap-3 py-2.5">
            <span className="flex size-7 items-center justify-center rounded-lg bg-primary/10 text-xs font-bold text-primary">{workspace.name.slice(0, 2).toUpperCase()}</span>
            <span className="min-w-0 flex-1"><span className="block truncate font-medium">{workspace.name}</span><span className="block text-xs text-muted-foreground">{workspace.role ?? "Miembro"}</span></span>
            {workspace.id === activeId && <Check className="size-4 text-primary" />}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => setCreateOpen(true)}><Plus className="size-4" />Crear workspace</DropdownMenuItem>
      </DropdownMenuContent>
      <Dialog open={createOpen} onOpenChange={handleCreateOpenChange}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Creá tu workspace</DialogTitle>
            <DialogDescription>Vas a empezar con un espacio independiente para tu equipo, conversaciones y configuración.</DialogDescription>
          </DialogHeader>

          <form className="space-y-4" onSubmit={create}>
            <div className="space-y-2">
              <label className="text-sm font-medium" htmlFor="workspace-name">Nombre del workspace</label>
              <Input
                autoFocus
                id="workspace-name"
                maxLength={100}
                onChange={(event) => {
                  setWorkspaceName(event.target.value)
                  if (createError) setCreateError(null)
                }}
                placeholder="Ej. Equipo comercial"
                value={workspaceName}
                aria-describedby={createError ? "workspace-name-error" : "workspace-name-hint"}
                aria-invalid={Boolean(createError)}
              />
              {createError ? (
                <p id="workspace-name-error" role="alert" className="text-sm text-destructive">{createError}</p>
              ) : (
                <p id="workspace-name-hint" className="text-xs text-muted-foreground">Va a quedar como tu espacio activo. Podés invitar personas y configurar canales después.</p>
              )}
            </div>

            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => handleCreateOpenChange(false)} disabled={loading}>Cancelar</Button>
              <Button type="submit" disabled={loading || workspaceName.trim().length < 2}>
                {loading && <Loader2 className="animate-spin" aria-hidden="true" />}
                {loading ? "Creando..." : "Crear workspace"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </DropdownMenu>
  )
}
