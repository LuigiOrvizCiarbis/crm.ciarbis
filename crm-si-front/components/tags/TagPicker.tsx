"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, Loader2, Plus, Search, TagIcon } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { useToast } from "@/components/Toast"
import { attachContactTags, attachConversationTags, createTag, detachContactTag, detachConversationTag, getTags, type Tag } from "@/lib/api/tags"
import { cn } from "@/lib/utils"
import { TagChips } from "./TagChips"

type TagTarget = "contact" | "conversation"

interface TagPickerProps {
  target: TagTarget
  targetId: number
  value?: Tag[]
  onChange?: (tags: Tag[]) => void
  buttonLabel?: string
  compact?: boolean
  className?: string
  /**
   * "popover": botón que abre un popover (default).
   * "inline": busqueda + lista renderizadas directamente, pensado para sheets/drawers
   * mobile donde un popover anidado no funciona bien. Usa la paleta oscura del chat.
   */
  variant?: "popover" | "inline"
}

const DEFAULT_COLORS = ["#0ea5e9", "#10b981", "#f59e0b", "#ef4444", "#8b5cf6", "#64748b"]

export function TagPicker({
  target,
  targetId,
  value = [],
  onChange,
  buttonLabel = "Etiquetas",
  compact = false,
  className,
  variant = "popover",
}: TagPickerProps) {
  const { addToast } = useToast()
  const isInline = variant === "inline"
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState("")
  const [tags, setTags] = useState<Tag[]>([])
  const [loading, setLoading] = useState(false)
  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)

  const selectedIds = useMemo(() => new Set(value.map((tag) => tag.id)), [value])
  const normalizedQuery = query.trim().toLowerCase()
  const filteredTags = tags.filter((tag) => tag.name.toLowerCase().includes(normalizedQuery))
  const canCreate = normalizedQuery.length > 0 && !tags.some((tag) => tag.name.toLowerCase() === normalizedQuery)

  const shouldLoad = isInline || open

  useEffect(() => {
    if (!shouldLoad) return

    let cancelled = false
    setLoading(true)
    setLoadFailed(false)
    getTags()
      .then((loadedTags) => {
        if (!cancelled) setTags(loadedTags)
      })
      .catch((error) => {
        if (cancelled) return
        setLoadFailed(true)
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
  }, [shouldLoad, reloadKey, addToast])

  const attachTag = async (tag: Tag) => {
    if (selectedIds.has(tag.id)) return

    setSavingId(tag.id)
    try {
      if (target === "contact") {
        await attachContactTags(targetId, [tag.id])
      } else {
        await attachConversationTags(targetId, [tag.id])
      }
      onChange?.([...value, tag].sort((a, b) => a.name.localeCompare(b.name)))
    } catch (error) {
      addToast({
        type: "error",
        title: "No se pudo asignar la etiqueta",
        description: error instanceof Error ? error.message : "Error desconocido",
      })
    } finally {
      setSavingId(null)
    }
  }

  const removeTag = async (tag: Tag) => {
    setSavingId(tag.id)
    try {
      if (target === "contact") {
        await detachContactTag(targetId, tag.id)
      } else {
        await detachConversationTag(targetId, tag.id)
      }
      onChange?.(value.filter((selectedTag) => selectedTag.id !== tag.id))
    } catch (error) {
      addToast({
        type: "error",
        title: "No se pudo quitar la etiqueta",
        description: error instanceof Error ? error.message : "Error desconocido",
      })
    } finally {
      setSavingId(null)
    }
  }

  const handleCreate = async () => {
    const name = query.trim()
    if (!name) return

    setCreating(true)
    try {
      const color = DEFAULT_COLORS[tags.length % DEFAULT_COLORS.length]
      const tag = await createTag({ name, color })
      setTags((current) => [...current, tag].sort((a, b) => a.name.localeCompare(b.name)))
      setQuery("")
      await attachTag(tag)
    } catch (error) {
      addToast({
        type: "error",
        title: "No se pudo crear la etiqueta",
        description: error instanceof Error ? error.message : "Error desconocido",
      })
    } finally {
      setCreating(false)
    }
  }

  const renderList = () => {
    if (loading) {
      return (
        <div
          className={cn(
            "flex items-center gap-2 px-2 py-6 text-sm",
            isInline ? "text-[#9AA4B2]" : "text-muted-foreground",
          )}
        >
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          Cargando etiquetas
        </div>
      )
    }

    if (loadFailed) {
      return (
        <div
          className={cn(
            "flex flex-col items-start gap-2 px-2 py-4 text-sm",
            isInline ? "text-[#9AA4B2]" : "text-muted-foreground",
          )}
        >
          <span>No se pudieron cargar las etiquetas.</span>
          <Button type="button" variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
            Reintentar
          </Button>
        </div>
      )
    }

    return (
      <>
        {filteredTags.map((tag) => {
          const selected = selectedIds.has(tag.id)
          const saving = savingId === tag.id
          return (
            <button
              key={tag.id}
              type="button"
              className={cn(
                "flex w-full items-center gap-3 rounded-md px-2 text-left text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait disabled:opacity-60",
                isInline
                  ? "py-2.5 text-[#D8DEE9] active:bg-[#1A1F2B] hover:bg-[#1A1F2B]"
                  : "py-2 hover:bg-accent",
              )}
              disabled={saving}
              aria-pressed={selected}
              onClick={() => (selected ? removeTag(tag) : attachTag(tag))}
            >
              <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: tag.color }} aria-hidden="true" />
              <span className="min-w-0 flex-1 truncate">{tag.name}</span>
              {saving ? (
                <Loader2 className={cn("h-4 w-4 animate-spin", isInline ? "text-[#9AA4B2]" : "text-muted-foreground")} />
              ) : selected ? (
                <Check className={cn("h-4 w-4", isInline ? "text-[#00E18C]" : "text-primary")} />
              ) : null}
            </button>
          )
        })}
        {canCreate && (
          <button
            type="button"
            className={cn(
              "mt-1 flex w-full items-center gap-3 rounded-md border border-dashed px-2 text-left text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait disabled:opacity-60",
              isInline
                ? "border-[#2A3344] py-2.5 text-[#9AA4B2] active:bg-[#1A1F2B] hover:bg-[#1A1F2B] hover:text-[#D8DEE9]"
                : "py-2 text-muted-foreground hover:bg-accent hover:text-foreground",
            )}
            disabled={creating}
            onClick={handleCreate}
          >
            {creating ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Plus className="h-4 w-4" aria-hidden="true" />}
            Crear "{query.trim()}"
          </button>
        )}
        {!filteredTags.length && !canCreate && (
          <div className={cn("px-2 py-6 text-sm", isInline ? "text-[#9AA4B2]" : "text-muted-foreground")}>
            No hay etiquetas.
          </div>
        )}
      </>
    )
  }

  if (isInline) {
    return (
      <div className={cn("space-y-3", className)}>
        <TagChips
          tags={value}
          removable
          onRemove={removeTag}
          emptyLabel="Sin etiquetas"
        />
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#9AA4B2]" />
          <Input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Buscar o crear etiqueta"
            className="h-10 border-[#1e2533] bg-[#0F1117] pl-9 text-[#D8DEE9] placeholder:text-[#9AA4B2]/70 focus-visible:border-[#00F7FF]/60 focus-visible:ring-[#00F7FF]/20"
          />
        </div>
        <div className="max-h-48 overflow-y-auto rounded-lg border border-[#1e2533] bg-[#0F1117] p-1.5">
          {renderList()}
        </div>
      </div>
    )
  }

  return (
    <div className={cn("space-y-2", className)}>
      {!compact && (
        <TagChips tags={value} removable onRemove={removeTag} emptyLabel="Sin etiquetas" />
      )}
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn("h-8 gap-2 bg-transparent", compact && "h-7 px-2 text-xs")}
            aria-label={buttonLabel ? undefined : "Gestionar etiquetas"}
          >
            <TagIcon className="h-3.5 w-3.5" aria-hidden="true" />
            {buttonLabel}
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-80 max-w-[calc(100vw-2rem)] p-0" sideOffset={8}>
          <div className="border-b p-3">
            <Input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Buscar o crear etiqueta"
              className="h-9"
            />
          </div>
          <div className="max-h-72 overflow-y-auto p-2">{renderList()}</div>
        </PopoverContent>
      </Popover>
    </div>
  )
}
