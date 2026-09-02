"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { Command } from "cmdk"
import { Dialog, DialogContent } from "@/components/ui/dialog"
import { useAppStore } from "@/store/useAppStore"
import {
  Plus,
  Zap,
  CreditCard,
  Calendar,
  Search,
} from "lucide-react"
import { useToast } from "./Toast"
import { NAVIGATION_ITEMS, resolveNavigationLabel } from "@/data/navigation"
import { useAuthStore } from "@/store/useAuthStore"
import { useTranslation } from "@/hooks/useTranslation"

export function CommandPalette() {
  const router = useRouter()
  const { addToast } = useToast()
  const { commandPaletteOpen, setCommandPaletteOpen } = useAppStore()
  const navigationLabels = useAuthStore((state) => state.user?.tenant?.navigation_labels)
  const { t } = useTranslation()
  const [search, setSearch] = useState("")

  useEffect(() => {
    const down = (e: KeyboardEvent) => {
      if (e.key === "k" && (e.metaKey || e.ctrlKey)) {
        e.preventDefault()
        setCommandPaletteOpen(!commandPaletteOpen)
      }

      // Global shortcuts
      if (!commandPaletteOpen) {
        const tag = (e.target as HTMLElement)?.tagName
        const isInput = tag === "INPUT" || tag === "TEXTAREA" || (e.target as HTMLElement)?.isContentEditable
        if (isInput) return

        if (e.key === "g" && e.ctrlKey) {
          e.preventDefault()
          return
        }

        if (e.key === "g") {
          const nextKey = new Promise<string>((resolve) => {
            const handler = (e: KeyboardEvent) => {
              document.removeEventListener("keydown", handler)
              resolve(e.key)
            }
            document.addEventListener("keydown", handler)
          })

          nextKey.then((key) => {
            switch (key) {
              case "p":
                router.push("/dashboard")
                break
              case "c":
                router.push("/chats")
                break
              case "o":
                router.push("/oportunidades")
                break
            }
          })
        }

        if (e.key === "n" && !e.ctrlKey && !e.metaKey) {
          e.preventDefault()
          addToast({
            type: "success",
            title: "Nuevo lead creado",
            description: "Lead agregado al pipeline",
          })
        }
      }
    }

    document.addEventListener("keydown", down)
    return () => document.removeEventListener("keydown", down)
  }, [commandPaletteOpen, setCommandPaletteOpen, router, addToast])

  const runCommand = (command: () => void) => {
    setCommandPaletteOpen(false)
    command()
  }

  return (
    <Dialog open={commandPaletteOpen} onOpenChange={setCommandPaletteOpen}>
      <DialogContent className="p-0 max-w-[640px]">
        <Command className="rounded-2xl border-0">
          <div className="flex items-center border-b px-3">
            <Search className="mr-2 h-4 w-4 shrink-0 opacity-50" />
            <Command.Input
              placeholder="Buscar acciones..."
              value={search}
              onValueChange={setSearch}
              className="flex h-11 w-full rounded-md bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
            />
          </div>
          <Command.List className="max-h-[300px] overflow-y-auto p-2">
            <Command.Empty className="py-6 text-center text-sm text-muted-foreground">
              No se encontraron resultados.
            </Command.Empty>

            <Command.Group heading="Navegación">
              {NAVIGATION_ITEMS.map((item) => {
                const label = resolveNavigationLabel(item, navigationLabels, t)
                const defaultLabel = t(item.labelKey)
                const shortcut = item.key === "dashboard" ? "G P" : item.key === "chats" ? "G C" : item.key === "pipeline" ? "G O" : null
                const Icon = item.icon

                return (
                  <Command.Item
                    key={item.key}
                    value={`${label} ${defaultLabel}`}
                    onSelect={() => runCommand(() => router.push(item.href))}
                    className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg cursor-pointer hover:bg-accent"
                  >
                    <Icon className="w-4 h-4" />
                    {label}
                    {shortcut ? <kbd className="ml-auto text-xs bg-muted px-1.5 py-0.5 rounded">{shortcut}</kbd> : null}
                  </Command.Item>
                )
              })}
            </Command.Group>

            <Command.Group heading="Acciones">
              <Command.Item
                onSelect={() =>
                  runCommand(() => {
                    addToast({
                      type: "success",
                      title: "Nuevo lead creado",
                      description: "Lead agregado al pipeline",
                    })
                  })
                }
                className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg cursor-pointer hover:bg-accent"
              >
                <Plus className="w-4 h-4" />
                Nuevo lead
                <kbd className="ml-auto text-xs bg-muted px-1.5 py-0.5 rounded">N</kbd>
              </Command.Item>
              <Command.Item
                onSelect={() =>
                  runCommand(() => {
                    addToast({
                      type: "info",
                      title: "Conectar canal",
                      description: "Abriendo wizard de conexión...",
                    })
                  })
                }
                className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg cursor-pointer hover:bg-accent"
              >
                <Zap className="w-4 h-4" />
                Conectar canal
              </Command.Item>
              <Command.Item
                onSelect={() =>
                  runCommand(() => {
                    addToast({
                      type: "success",
                      title: "Pago registrado",
                      description: "Pago procesado correctamente",
                    })
                  })
                }
                className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg cursor-pointer hover:bg-accent"
              >
                <CreditCard className="w-4 h-4" />
                Registrar pago
              </Command.Item>
              <Command.Item
                onSelect={() =>
                  runCommand(() => {
                    addToast({
                      type: "success",
                      title: "Demo agendada",
                      description: "Demo programada para mañana a las 15:00",
                    })
                  })
                }
                className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg cursor-pointer hover:bg-accent"
              >
                <Calendar className="w-4 h-4" />
                Agendar demo
              </Command.Item>
            </Command.Group>
          </Command.List>
        </Command>
      </DialogContent>
    </Dialog>
  )
}
