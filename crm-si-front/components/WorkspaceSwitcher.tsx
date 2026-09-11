"use client"

import { type FormEvent, useEffect, useMemo, useState } from "react"
import { ArrowRight, Building2, Check, ChevronsUpDown, Loader2, Plus } from "lucide-react"
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
        <DialogContent className="overflow-hidden border-border/70 p-0 sm:max-w-md">
          <div className="relative overflow-hidden bg-linear-to-br from-primary via-primary to-secondary px-6 pb-8 pt-7 text-primary-foreground">
            <div className="absolute -right-8 -top-10 size-36 rounded-full border-[18px] border-white/10" />
            <div className="absolute -bottom-10 left-14 size-28 rounded-full bg-white/8 blur-2xl" />
            <div className="relative flex items-start justify-between gap-5">
              <div className="flex size-11 items-center justify-center rounded-2xl border border-white/20 bg-white/12 shadow-lg shadow-black/10">
                <Building2 className="size-5" />
              </div>
              <span className="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.16em]">Nuevo espacio</span>
            </div>
            <DialogHeader className="relative mt-7 space-y-2 text-left">
              <DialogTitle className="text-2xl font-semibold tracking-tight text-white">Creá tu workspace</DialogTitle>
              <DialogDescription className="max-w-sm text-sm leading-6 text-white/75">Vas a empezar con un espacio independiente para tu equipo, conversaciones y configuración.</DialogDescription>
            </DialogHeader>
          </div>

          <form className="space-y-5 px-6 pb-6 pt-5" onSubmit={create}>
            <div className="space-y-2">
              <label className="text-sm font-semibold" htmlFor="workspace-name">Nombre del workspace</label>
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
                className="h-11 rounded-xl px-3.5"
              />
              {createError ? (
                <p id="workspace-name-error" role="alert" className="text-sm text-destructive">{createError}</p>
              ) : (
                <p id="workspace-name-hint" className="text-xs leading-5 text-muted-foreground">Podés invitar personas y configurar canales después.</p>
              )}
            </div>

            <div className="flex items-center gap-3 rounded-xl border border-primary/10 bg-primary/5 px-3.5 py-3">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-xs font-bold text-primary-foreground">{workspaceName.trim().slice(0, 2).toUpperCase() || "SI"}</span>
              <span className="min-w-0 text-sm text-muted-foreground">{workspaceName.trim() ? <><span className="font-medium text-foreground">{workspaceName.trim()}</span> será tu espacio activo.</> : "Tu nuevo espacio aparecerá acá."}</span>
            </div>

            <DialogFooter className="gap-2 sm:gap-2">
              <Button type="button" variant="ghost" onClick={() => handleCreateOpenChange(false)} disabled={loading}>Cancelar</Button>
              <Button type="submit" disabled={loading || workspaceName.trim().length < 2} className="min-w-[9.5rem] rounded-xl">
                {loading ? <Loader2 className="animate-spin" /> : <ArrowRight />}
                {loading ? "Creando..." : "Crear workspace"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </DropdownMenu>
  )
}
