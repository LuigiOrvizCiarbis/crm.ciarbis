"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, Loader2, Plus, Search, TagIcon } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { useToast } from "@/components/Toast"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { attachContactTags, attachConversationTags, createTag, detachContactTag, detachConversationTag, getTags, updateTag, type Tag } from "@/lib/api/tags"
import { cn } from "@/lib/utils"
import { useTagColorsStore } from "@/store/useTagColorsStore"
import { TagChips } from "./TagChips"
import { TagColorPalette } from "./TagColorPalette"
import { nextTagColor } from "./tag-colors"

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
   * mobile donde un popover anidado no funciona bien.
   */
  variant?: "popover" | "inline"
}

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
  const { t } = useTranslation()
  const canManage = usePermission("tags.manage")
  const colorOverrides = useTagColorsStore((state) => state.colors)
  const setStoredColor = useTagColorsStore((state) => state.setColor)
  const isInline = variant === "inline"
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState("")
  const [tags, setTags] = useState<Tag[]>([])
  const [loading, setLoading] = useState(false)
  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  // Paleta desplegada debajo de la fila: el selector ya vive en un popover o
  // en una hoja, y otro popover adentro rompe el foco en las hojas.
  const [colorOpenId, setColorOpenId] = useState<number | null>(null)

  const selectedIds = useMemo(() => new Set(value.map((tag) => tag.id)), [value])
  const normalizedQuery = query.trim().toLowerCase()
  const filteredTags = tags.filter((tag) => tag.name.toLowerCase().includes(normalizedQuery))
  const canCreate = normalizedQuery.length > 0 && !tags.some((tag) => tag.name.toLowerCase() === normalizedQuery)
  const colorOf = (tag: Tag) => colorOverrides[tag.id] ?? tag.color

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
      const tag = await createTag({ name, color: nextTagColor(tags.length) })
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

  // Optimista: el chip cambia en toda la pantalla al elegir; si falla, vuelve.
  const changeColor = async (tag: Tag, color: string) => {
    const previous = colorOf(tag)
    setStoredColor(tag.id, color)
    setColorOpenId(null)
    try {
      await updateTag(tag.id, { color })
    } catch (error) {
      setStoredColor(tag.id, previous)
      addToast({
        type: "error",
        title: t("settings.tags.colorSaveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }

  const mutedText = "text-muted-foreground"

  const renderList = () => {
    if (loading) {
      return (
        <div role="status" className={cn("flex items-center gap-2 px-2 py-6 text-sm", mutedText)}>
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          Cargando etiquetas
        </div>
      )
    }

    if (loadFailed) {
      return (
        <div className={cn("flex flex-col items-start gap-2 px-2 py-4 text-sm", mutedText)}>
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
          const color = colorOf(tag)
          const paletteOpen = colorOpenId === tag.id
          return (
            <div key={tag.id}>
              <div className="flex items-center gap-1">
                {canManage ? (
                  <button
                    type="button"
                    className="grid size-8 shrink-0 place-items-center rounded-md outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 pointer-coarse:size-10"
                    aria-label={t("settings.tags.changeColor", { name: tag.name })}
                    aria-expanded={paletteOpen}
                    onClick={() => setColorOpenId(paletteOpen ? null : tag.id)}
                  >
                    <span className="size-3 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />
                  </button>
                ) : (
                  <span className="grid size-8 shrink-0 place-items-center" aria-hidden="true">
                    <span className="size-3 rounded-full" style={{ backgroundColor: color }} />
                  </span>
                )}
                <button
                  type="button"
                  className={cn(
                    "flex min-w-0 flex-1 items-center gap-3 rounded-md px-2 text-left text-sm outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait disabled:opacity-60",
                    isInline ? "py-2.5" : "py-2",
                  )}
                  disabled={saving}
                  aria-pressed={selected}
                  onClick={() => (selected ? removeTag(tag) : attachTag(tag))}
                >
                  <span className="min-w-0 flex-1 truncate">{tag.name}</span>
                  {saving ? (
                    <Loader2 className={cn("h-4 w-4 animate-spin", mutedText)} aria-hidden="true" />
                  ) : selected ? (
                    <Check className="h-4 w-4 text-primary" aria-hidden="true" />
                  ) : null}
                </button>
              </div>
              {paletteOpen && (
                <TagColorPalette
                  className="px-2 pb-3 pt-1.5"
                  size="sm"
                  value={color}
                  label={t("settings.tags.colorLabel", { name: tag.name })}
                  onChange={(hex) => void changeColor(tag, hex)}
                />
              )}
            </div>
          )
        })}
        {canCreate && (
          <button
            type="button"
            className={cn(
              "mt-1 flex w-full items-center gap-3 rounded-md border border-dashed border-border px-2 text-left text-sm outline-none transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait disabled:opacity-60",
              mutedText,
              isInline ? "py-2.5" : "py-2",
            )}
            disabled={creating}
            onClick={handleCreate}
          >
            {creating ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Plus className="h-4 w-4" aria-hidden="true" />}
            Crear "{query.trim()}"
          </button>
        )}
        {!filteredTags.length && !canCreate && (
          <div className={cn("px-2 py-6 text-sm", mutedText)}>
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
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
          <Input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Buscar o crear etiqueta"
            aria-label="Buscar o crear etiqueta"
            className="h-10 pl-9"
          />
        </div>
        <div className="max-h-64 overflow-y-auto rounded-lg border border-border bg-background p-1.5">
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
      <Popover open={open} onOpenChange={(next) => { setOpen(next); if (!next) setColorOpenId(null) }}>
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
              aria-label="Buscar o crear etiqueta"
              className="h-9"
            />
          </div>
          <div className="max-h-72 overflow-y-auto p-2">{renderList()}</div>
        </PopoverContent>
      </Popover>
    </div>
  )
}
