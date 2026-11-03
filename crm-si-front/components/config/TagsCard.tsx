"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import Link from "next/link"
import { Loader2, Search, Tags, Trash2 } from "lucide-react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { TagColorPalette } from "@/components/tags/TagColorPalette"
import { useToast } from "@/components/Toast"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import { deleteTag, getTagUsage, getTags, TagInUseError, updateTag, type Tag, type TagUsage } from "@/lib/api/tags"
import { useTagColorsStore } from "@/store/useTagColorsStore"

/** Con pocas etiquetas el buscador sólo agrega ruido. */
const SEARCH_THRESHOLD = 15

type Translate = ReturnType<typeof useTranslation>["t"]

function plural(t: Translate, key: string, count: number) {
  return t(`${key}_${count === 1 ? "one" : "other"}`, { count: count.toLocaleString("es-AR") })
}

function usageParts(t: Translate, usage: Pick<TagUsage, "contacts_count" | "conversations_count" | "opportunities_count">) {
  return [
    usage.contacts_count > 0 && plural(t, "settings.tags.contacts", usage.contacts_count),
    usage.conversations_count > 0 && plural(t, "settings.tags.conversations", usage.conversations_count),
    usage.opportunities_count > 0 && plural(t, "settings.tags.opportunities", usage.opportunities_count),
  ].filter((part): part is string => Boolean(part))
}

export function TagsCard() {
  const { t, language } = useTranslation()
  const { addToast } = useToast()
  const setStoredColor = useTagColorsStore((state) => state.setColor)
  const [tags, setTags] = useState<Tag[]>([])
  const [loading, setLoading] = useState(true)
  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const [query, setQuery] = useState("")
  const [colorOpenId, setColorOpenId] = useState<number | null>(null)
  const [toDelete, setToDelete] = useState<Tag | null>(null)
  const rowRefs = useRef(new Map<number, HTMLButtonElement>())
  const searchRef = useRef<HTMLInputElement>(null)
  const focusAfterCloseRef = useRef<number | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setLoadFailed(false)
    getTags({ withCounts: true })
      .then((loaded) => { if (!cancelled) setTags(loaded) })
      .catch(() => { if (!cancelled) setLoadFailed(true) })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [reloadKey])

  const normalizedQuery = query.trim().toLowerCase()
  const visibleTags = useMemo(
    () => (normalizedQuery ? tags.filter((tag) => tag.name.toLowerCase().includes(normalizedQuery)) : tags),
    [tags, normalizedQuery],
  )
  const listFormat = useMemo(() => new Intl.ListFormat(language, { type: "conjunction" }), [language])

  const changeColor = async (tag: Tag, color: string) => {
    const previous = tag.color
    setColorOpenId(null)
    setTags((current) => current.map((item) => (item.id === tag.id ? { ...item, color } : item)))
    setStoredColor(tag.id, color)
    try {
      await updateTag(tag.id, { color })
    } catch (error) {
      setTags((current) => current.map((item) => (item.id === tag.id ? { ...item, color: previous } : item)))
      setStoredColor(tag.id, previous)
      addToast({
        type: "error",
        title: t("settings.tags.colorSaveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }

  const handleDeleted = (tag: Tag) => {
    const index = visibleTags.findIndex((item) => item.id === tag.id)
    const next = visibleTags[index + 1] ?? visibleTags[index - 1]
    focusAfterCloseRef.current = next?.id ?? null
    setTags((current) => current.filter((item) => item.id !== tag.id))
    addToast({ type: "success", title: t("settings.tags.deleted") })
  }

  const renderBody = () => {
    if (loading) {
      return (
        <ul className="divide-y divide-border border-y border-border" aria-busy="true" aria-label={t("settings.tags.loading")}>
          {[0, 1, 2, 3].map((row) => (
            <li key={row} className="flex items-center gap-3 py-3">
              <Skeleton className="size-8 rounded-md" />
              <div className="flex-1 space-y-1.5">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-3 w-48" />
              </div>
            </li>
          ))}
        </ul>
      )
    }

    if (loadFailed) {
      return (
        <div role="alert" className="flex flex-wrap items-center gap-3 border-y border-border py-4 text-sm text-muted-foreground">
          <span>{t("settings.tags.loadError")}</span>
          <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
            {t("settings.tags.retry")}
          </Button>
        </div>
      )
    }

    if (tags.length === 0) {
      return <p className="border-y border-border py-4 text-sm text-muted-foreground">{t("settings.tags.empty")}</p>
    }

    return (
      <>
        {tags.length > SEARCH_THRESHOLD && (
          <div className="relative mb-3 max-w-sm">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <Input
              ref={searchRef}
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={t("settings.tags.searchPlaceholder")}
              aria-label={t("settings.tags.searchLabel")}
              className="pl-9"
            />
          </div>
        )}

        {visibleTags.length === 0 ? (
          <div className="flex flex-wrap items-center gap-3 border-y border-border py-4 text-sm text-muted-foreground">
            <span>{t("settings.tags.noResults")}</span>
            <Button variant="outline" size="sm" onClick={() => { setQuery(""); searchRef.current?.focus() }}>
              {t("settings.tags.clearSearch")}
            </Button>
          </div>
        ) : (
          <ul className="divide-y divide-border border-y border-border">
            {visibleTags.map((tag) => {
              const parts = usageParts(t, {
                contacts_count: tag.contacts_count ?? 0,
                conversations_count: tag.conversations_count ?? 0,
                opportunities_count: tag.opportunities_count ?? 0,
              })
              return (
                <li key={tag.id} className="flex items-center gap-3 py-2.5">
                  <Popover open={colorOpenId === tag.id} onOpenChange={(open) => setColorOpenId(open ? tag.id : null)}>
                    <PopoverTrigger asChild>
                      <button
                        ref={(node) => { if (node) rowRefs.current.set(tag.id, node); else rowRefs.current.delete(tag.id) }}
                        type="button"
                        className="grid size-8 shrink-0 place-items-center rounded-md outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 pointer-coarse:size-10"
                        aria-label={t("settings.tags.changeColor", { name: tag.name })}
                      >
                        <span className="size-3.5 rounded-full" style={{ backgroundColor: tag.color }} aria-hidden="true" />
                      </button>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-auto max-w-[calc(100vw-2rem)] p-3">
                      <TagColorPalette
                        value={tag.color}
                        label={t("settings.tags.colorLabel", { name: tag.name })}
                        onChange={(hex) => void changeColor(tag, hex)}
                      />
                    </PopoverContent>
                  </Popover>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-foreground">{tag.name}</p>
                    <p className="truncate text-xs tabular-nums text-muted-foreground">
                      {parts.length > 0 ? parts.join(" · ") : t("settings.tags.unused")}
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0 text-muted-foreground hover:text-destructive pointer-coarse:size-10"
                    onClick={() => setToDelete(tag)}
                    aria-label={t("settings.tags.delete", { name: tag.name })}
                  >
                    <Trash2 className="size-4" aria-hidden="true" />
                  </Button>
                </li>
              )
            })}
          </ul>
        )}
      </>
    )
  }

  return (
    <SettingsBlock title={t("settings.tags.title")} description={t("settings.tags.description")} icon={Tags} measure="prose">
      {renderBody()}
      <DeleteTagDialog
        tag={toDelete}
        listFormat={listFormat}
        onClose={() => setToDelete(null)}
        onDeleted={handleDeleted}
        onCloseAutoFocus={(event) => {
          // El disparador ya no existe si se borró: el foco pasa a la fila vecina.
          const nextId = focusAfterCloseRef.current
          focusAfterCloseRef.current = null
          if (nextId === null) return
          event.preventDefault()
          rowRefs.current.get(nextId)?.focus()
        }}
      />
    </SettingsBlock>
  )
}

interface DeleteTagDialogProps {
  tag: Tag | null
  listFormat: Intl.ListFormat
  onClose: () => void
  onDeleted: (tag: Tag) => void
  onCloseAutoFocus: (event: Event) => void
}

type UsageState =
  | { status: "loading" }
  | { status: "error" }
  | { status: "ready"; usage: TagUsage }

function DeleteTagDialog({ tag, listFormat, onClose, onDeleted, onCloseAutoFocus }: DeleteTagDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const [usageState, setUsageState] = useState<UsageState>({ status: "loading" })
  const [deleting, setDeleting] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  // Al cerrar, `tag` pasa a null antes de que termine la animación de salida:
  // se sigue mostrando la última para que el título no quede vacío.
  const [shownTag, setShownTag] = useState<Tag | null>(tag)
  if (tag && tag !== shownTag) setShownTag(tag)

  useEffect(() => {
    if (!tag) return
    let cancelled = false
    setUsageState({ status: "loading" })
    getTagUsage(tag.id)
      .then((usage) => { if (!cancelled) setUsageState({ status: "ready", usage }) })
      .catch(() => { if (!cancelled) setUsageState({ status: "error" }) })
    return () => { cancelled = true }
  }, [tag, reloadKey])

  const confirmDelete = useCallback(async () => {
    if (!tag || usageState.status !== "ready") return
    setDeleting(true)
    try {
      await deleteTag(tag.id)
      onDeleted(tag)
      onClose()
    } catch (error) {
      if (error instanceof TagInUseError) {
        // Alguien la empezó a usar entre el cálculo y el borrado: se muestra el bloqueo.
        setUsageState({ status: "ready", usage: { ...usageState.usage, blocking: error.blocking } })
      } else {
        addToast({
          type: "error",
          title: t("settings.tags.deleteError"),
          description: error instanceof Error ? error.message : undefined,
        })
      }
    } finally {
      setDeleting(false)
    }
  }, [tag, usageState, onDeleted, onClose, addToast, t])

  const usage = usageState.status === "ready" ? usageState.usage : null
  const blocking = usage?.blocking
  const blocked = Boolean(blocking && (blocking.automations.length > 0 || blocking.broadcasts.length > 0))
  const parts = usage ? usageParts(t, usage) : []

  return (
    <AlertDialog open={tag !== null} onOpenChange={(open) => { if (!open && !deleting) onClose() }}>
      <AlertDialogContent onCloseAutoFocus={onCloseAutoFocus}>
        <AlertDialogHeader>
          <AlertDialogTitle>{t("settings.tags.deleteTitle", { name: shownTag?.name ?? "" })}</AlertDialogTitle>
          <AlertDialogDescription asChild>
            <div aria-live="polite" className="space-y-3 text-sm text-muted-foreground">
              {usageState.status === "loading" && (
                <p className="flex items-center gap-2">
                  <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                  {t("settings.tags.deleteLoading")}
                </p>
              )}
              {usageState.status === "error" && (
                <p className="flex flex-wrap items-center gap-2">
                  {t("settings.tags.deleteUsageError")}
                  <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
                    {t("settings.tags.retry")}
                  </Button>
                </p>
              )}
              {usage && !blocked && (
                <p>
                  {parts.length > 0
                    ? t("settings.tags.deleteUsage", { list: listFormat.format(parts) })
                    : t("settings.tags.deleteUnused")}
                </p>
              )}
              {usage && blocked && blocking && (
                <div className="space-y-3 rounded-lg border border-warning/30 bg-warning/10 p-3 text-foreground">
                  <p className="font-medium text-warning">{t("settings.tags.blockedLead")}</p>
                  {blocking.automations.length > 0 && (
                    <div>
                      <p className="text-xs font-medium text-muted-foreground">{t("settings.tags.blockedAutomations")}</p>
                      <ul className="mt-1 space-y-0.5">
                        {blocking.automations.map((automation) => <li key={automation.id}>{automation.name}</li>)}
                      </ul>
                    </div>
                  )}
                  {blocking.broadcasts.length > 0 && (
                    <div>
                      <p className="text-xs font-medium text-muted-foreground">{t("settings.tags.blockedBroadcasts")}</p>
                      <ul className="mt-1 space-y-0.5">
                        {blocking.broadcasts.map((broadcast) => (
                          <li key={broadcast.id}>
                            <Link href={`/difusiones/${broadcast.id}`} className="rounded-sm underline underline-offset-2 outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50">
                              {broadcast.name}
                            </Link>{" "}
                            <span className="text-muted-foreground">
                              ({t(broadcast.status === "processing" ? "settings.tags.broadcastProcessing" : "settings.tags.broadcastScheduled")})
                            </span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  <p className="text-xs">{t("settings.tags.blockedHint")}</p>
                </div>
              )}
            </div>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={deleting}>{blocked ? t("common.close") : t("common.cancel")}</AlertDialogCancel>
          {!blocked && (
            <AlertDialogAction
              // Queda abierto mientras borra: el cierre llega cuando termina.
              onClick={(event) => {
                event.preventDefault()
                void confirmDelete()
              }}
              disabled={deleting || usageState.status !== "ready"}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleting && <Loader2 className="mr-2 size-4 animate-spin" aria-hidden="true" />}
              {deleting ? t("settings.tags.deleting") : t("settings.tags.confirm")}
            </AlertDialogAction>
          )}
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
