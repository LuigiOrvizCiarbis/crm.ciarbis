"use client"

import { useEffect, useMemo, useState } from "react"
import { Check, Loader2, Search, AlertTriangle } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { useToast } from "@/components/Toast"
import {
  bulkUpdateConversationTags,
  type BulkConversationTagAction,
} from "@/lib/api/conversations"
import { getTags, type Tag } from "@/lib/api/tags"
import { cn } from "@/lib/utils"

interface BulkTagsConversationsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  selectedIds: number[]
  onSuccess: () => void
}

export function BulkTagsConversationsDialog({
  open,
  onOpenChange,
  selectedIds,
  onSuccess,
}: BulkTagsConversationsDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [action, setAction] = useState<BulkConversationTagAction>("add")
  const [tags, setTags] = useState<Tag[]>([])
  const [loadingTags, setLoadingTags] = useState(false)
  const [selectedTagIds, setSelectedTagIds] = useState<Set<number>>(new Set())
  const [query, setQuery] = useState("")
  const [submitting, setSubmitting] = useState(false)

  const count = selectedIds.length

  useEffect(() => {
    if (!open) return
    setAction("add")
    setSelectedTagIds(new Set())
    setQuery("")
  }, [open])

  const [loadFailed, setLoadFailed] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    if (!open) return
    let cancelled = false
    setLoadingTags(true)
    setLoadFailed(false)
    getTags()
      .then((loaded) => {
        if (!cancelled) setTags(loaded)
      })
      .catch((err) => {
        if (!cancelled) setLoadFailed(true)
        addToast({
          type: "error",
          title: t("chats.bulk.errors.loadTags"),
          description: err instanceof Error ? err.message : "",
        })
      })
      .finally(() => {
        if (!cancelled) setLoadingTags(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, addToast, t, reloadKey])

  const filteredTags = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return tags
    return tags.filter((tag) => tag.name.toLowerCase().includes(q))
  }, [tags, query])

  const toggleTag = (tagId: number) => {
    setSelectedTagIds((prev) => {
      const next = new Set(prev)
      if (next.has(tagId)) next.delete(tagId)
      else next.add(tagId)
      return next
    })
  }

  const canApply =
    !submitting && count > 0 && (action === "replace" || selectedTagIds.size > 0)

  const handleApply = async () => {
    if (!canApply) return
    setSubmitting(true)
    try {
      const result = await bulkUpdateConversationTags({
        ids: selectedIds,
        action,
        tag_ids: Array.from(selectedTagIds),
      })

      if (result.failed > 0) {
        addToast({
          type: "info",
          title: t("chats.bulk.result.partial", {
            updated: result.updated,
            failed: result.failed,
          }),
        })
      } else {
        addToast({
          type: "success",
          title: t("chats.bulk.result.success", { updated: result.updated }),
        })
      }

      onOpenChange(false)
      onSuccess()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.bulk.errors.apply"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setSubmitting(false)
    }
  }

  const actionHint =
    action === "add"
      ? t("chats.bulk.dialog.hintAdd")
      : action === "remove"
        ? t("chats.bulk.dialog.hintRemove")
        : t("chats.bulk.dialog.hintReplace")

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("chats.bulk.dialog.tagsTitle")}</DialogTitle>
          <DialogDescription>
            {t("chats.bulk.dialog.subtitle", { count })}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label className="text-xs uppercase tracking-wide text-muted-foreground">
              {t("chats.bulk.dialog.actionLabel")}
            </Label>
            <RadioGroup
              value={action}
              onValueChange={(value) => setAction(value as BulkConversationTagAction)}
              className="grid grid-cols-3 gap-2"
            >
              {(["add", "remove", "replace"] as BulkConversationTagAction[]).map((opt) => (
                <Label
                  key={opt}
                  htmlFor={`bulk-conv-action-${opt}`}
                  className={cn(
                    "flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm transition has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50",
                    action === opt
                      ? "border-primary bg-primary/10 font-medium"
                      : "border-input hover:bg-accent",
                  )}
                >
                  <RadioGroupItem id={`bulk-conv-action-${opt}`} value={opt} className="sr-only" />
                  {t(`chats.bulk.dialog.action_${opt}`)}
                </Label>
              ))}
            </RadioGroup>
            <p
              className={cn(
                "flex items-start gap-2 text-xs",
                action === "replace" ? "text-warning" : "text-muted-foreground",
              )}
            >
              {action === "replace" && <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />}
              <span>{actionHint}</span>
            </p>
          </div>

          <div className="space-y-2">
            <Label className="text-xs uppercase tracking-wide text-muted-foreground">
              {t("chats.bulk.dialog.tagsLabel")}
              {selectedTagIds.size > 0 && (
                <Badge variant="secondary" className="ml-2">
                  {selectedTagIds.size}
                </Badge>
              )}
            </Label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" aria-hidden="true" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t("chats.bulk.dialog.searchTags")}
                aria-label={t("chats.bulk.dialog.searchTags")}
                className="pl-8"
              />
            </div>
            <div className="max-h-60 overflow-y-auto rounded-md border">
              {loadingTags ? (
                <div role="status" className="flex items-center gap-2 px-3 py-6 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                  {t("chats.bulk.dialog.loadingTags")}
                </div>
              ) : loadFailed ? (
                <div className="flex flex-col items-center gap-3 px-3 py-6 text-center text-sm text-muted-foreground">
                  <p>{t("chats.bulk.errors.loadTags")}</p>
                  <Button variant="outline" size="sm" onClick={() => setReloadKey((key) => key + 1)}>
                    {t("chats.retry")}
                  </Button>
                </div>
              ) : filteredTags.length === 0 ? (
                <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                  {t("chats.bulk.dialog.noTags")}
                </div>
              ) : (
                filteredTags.map((tag) => {
                  const selected = selectedTagIds.has(tag.id)
                  return (
                    <button
                      key={tag.id}
                      type="button"
                      onClick={() => toggleTag(tag.id)}
                      aria-pressed={selected}
                      className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-inset focus-visible:ring-ring/50"
                    >
                      <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: tag.color }} />
                      <span className="min-w-0 flex-1 truncate">{tag.name}</span>
                      {selected && <Check className="h-4 w-4 text-primary" />}
                    </button>
                  )
                })
              )}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            {t("chats.bulk.dialog.cancel")}
          </Button>
          <Button onClick={handleApply} disabled={!canApply}>
            {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t("chats.bulk.dialog.apply")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
