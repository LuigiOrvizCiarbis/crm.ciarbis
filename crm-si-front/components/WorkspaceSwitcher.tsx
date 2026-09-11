"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, ChevronsUpDown, Plus } from "lucide-react"
import { Button } from "@/components/ui/button"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"
import { getWorkspaceId, setWorkspaceId } from "@/lib/api/auth-token"
import { createWorkspace, getWorkspaces } from "@/lib/api/workspaces"

export function WorkspaceSwitcher({ collapsed = false }: { collapsed?: boolean }) {
  const { user, workspaces, setWorkspaces, setActiveWorkspace } = useAuthStore()
  const storedActiveId = useAuthStore((state) => state.activeWorkspaceId)
  const [loading, setLoading] = useState(false)
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

  async function create() {
    const name = window.prompt("Nombre del nuevo workspace")?.trim()
    if (!name) return
    setLoading(true)
    const result = await createWorkspace(name)
    if (result.data?.id) {
      setWorkspaces([...workspaces, result.data])
      setActiveWorkspace(result.data.id)
      window.location.assign("/chats")
    } else {
      setLoading(false)
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
        <DropdownMenuItem onSelect={() => void create()}><Plus className="size-4" />Crear workspace</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
