"use client"

import { useEffect, useMemo, useState } from "react"
import { Filter, Loader2, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useToast } from "@/components/Toast"
import { getTags, type Tag } from "@/lib/api/tags"

interface TagFilterMenuProps {
  selectedSlugs: string[]
  onChange: (slugs: string[]) => void
  label?: string
  align?: "start" | "center" | "end"
}

export function TagFilterMenu({
  selectedSlugs,
  onChange,
  label = "Etiquetas",
  align = "end",
}: TagFilterMenuProps) {
  const { addToast } = useToast()
  const [open, setOpen] = useState(false)
  const [tags, setTags] = useState<Tag[]>([])
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  const selectedSet = useMemo(() => new Set(selectedSlugs), [selectedSlugs])

  useEffect(() => {
    if (!open || tags.length > 0) return

    let cancelled = false
    setLoading(true)
    setFailed(false)
    getTags()
      .then((loadedTags) => {
        if (!cancelled) setTags(loadedTags)
      })
      .catch((error) => {
        if (cancelled) return
        setFailed(true)
        addToast({
          type: "error",
          title: "No se pudieron cargar las etiquetas",
          description: error instanceof Error ? error.message : "Error desconocido",
        })
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [open, tags.length, reloadKey, addToast])

  const toggle = (slug: string) => {
    if (selectedSet.has(slug)) {
      onChange(selectedSlugs.filter((selectedSlug) => selectedSlug !== slug))
      return
    }

    onChange([...selectedSlugs, slug])
  }

  return (
    <div className="flex items-center gap-2">
      <DropdownMenu open={open} onOpenChange={setOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm" className="gap-2 bg-transparent">
            <Filter className="h-4 w-4" aria-hidden="true" />
            {label}
            {selectedSlugs.length > 0 && (
              <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground">
                {selectedSlugs.length}
              </span>
            )}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align={align} className="w-64">
          <DropdownMenuLabel>Filtrar por etiquetas</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {loading ? (
            <div className="flex items-center gap-2 px-2 py-6 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              Cargando etiquetas
            </div>
          ) : failed ? (
            <div className="flex flex-col items-start gap-2 px-2 py-4 text-sm text-muted-foreground">
              <span>No se pudieron cargar las etiquetas.</span>
              <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
                Reintentar
              </Button>
            </div>
          ) : tags.length > 0 ? (
            tags.map((tag) => (
              <DropdownMenuCheckboxItem
                key={tag.id}
                checked={selectedSet.has(tag.slug)}
                onCheckedChange={() => toggle(tag.slug)}
                onSelect={(event) => event.preventDefault()}
              >
                <span className="mr-2 h-2.5 w-2.5 rounded-full" style={{ backgroundColor: tag.color }} aria-hidden="true" />
                <span className="truncate">{tag.name}</span>
              </DropdownMenuCheckboxItem>
            ))
          ) : (
            <div className="px-2 py-6 text-sm text-muted-foreground">No hay etiquetas creadas.</div>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
      {selectedSlugs.length > 0 && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 px-2 text-muted-foreground"
          onClick={() => onChange([])}
          aria-label="Limpiar filtro de etiquetas"
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </Button>
      )}
    </div>
  )
}
