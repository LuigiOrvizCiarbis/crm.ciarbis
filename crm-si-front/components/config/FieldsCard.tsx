"use client"

import { useEffect, useMemo, useState } from "react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { SettingsBlock } from "@/components/config/SettingsBlock"
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group"
import { Loader2, Pencil, Plus, SlidersHorizontal, Trash2, GripVertical } from "lucide-react"

import { cn } from "@/lib/utils"

import { useTranslation } from "@/hooks/useTranslation"
import { useToast } from "@/components/Toast"
import { useAuthStore } from "@/store/useAuthStore"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"
import { useProductFieldsStore } from "@/store/useProductFieldsStore"
import type { ContactField, ContactFieldType } from "@/lib/api/contact-fields"
import type { ProductField } from "@/lib/api/product-fields"

import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"

// Contact and product fields are structurally identical; a single field shape
// backs the whole card so the CRUD logic is written once.
type FieldRecord = ContactField | ProductField
type FieldType = ContactFieldType

const TYPE_OPTIONS: FieldType[] = [
  "text",
  "number",
  "date",
  "boolean",
  "select",
  "multi_select",
  "email",
  "url",
  "phone",
  "file",
]

type EntityKey = "contacts" | "products"

interface EntityConfig {
  key: EntityKey
  labelKey: string
  viewPermission: string
  managePermission: string
}

const ENTITIES: EntityConfig[] = [
  {
    key: "contacts",
    labelKey: "fields.entitySelector.contacts",
    viewPermission: "contact_fields.view",
    managePermission: "contact_fields.manage",
  },
  {
    key: "products",
    labelKey: "fields.entitySelector.products",
    viewPermission: "product_fields.view",
    managePermission: "product_fields.manage",
  },
]

interface FormState {
  label: string
  type: FieldType
  choices: string
  is_required: boolean
  is_unique: boolean
}

const emptyForm: FormState = {
  label: "",
  type: "text",
  choices: "",
  is_required: false,
  is_unique: false,
}

function needsOptions(type: FieldType): boolean {
  return type === "select" || type === "multi_select"
}

export function FieldsCard() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const permissions = useAuthStore((s) => s.permissions ?? [])

  // Both stores are always subscribed (hooks can't be conditional); the active
  // entity selects which one drives the card.
  const contactsStore = useContactFieldsStore()
  const productsStore = useProductFieldsStore()

  const visibleEntities = useMemo(
    () =>
      ENTITIES.filter(
        (e) => permissions.includes(e.viewPermission) || permissions.includes(e.managePermission),
      ),
    [permissions],
  )

  const [selectedEntity, setSelectedEntity] = useState<EntityKey>(
    visibleEntities[0]?.key ?? "contacts",
  )

  // Keep the selection valid if permissions change.
  useEffect(() => {
    if (visibleEntities.length > 0 && !visibleEntities.some((e) => e.key === selectedEntity)) {
      setSelectedEntity(visibleEntities[0].key)
    }
  }, [visibleEntities, selectedEntity])

  const entity = ENTITIES.find((e) => e.key === selectedEntity) ?? ENTITIES[0]
  const store = selectedEntity === "products" ? productsStore : contactsStore
  const canManage = permissions.includes(entity.managePermission)

  const { fields, loaded, loading, fetch, create, update, remove, reorder } = store

  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<FieldRecord | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)
  const [saving, setSaving] = useState(false)

  // Load definitions for whichever entity is currently selected. Guard on
  // visibility so we don't fire a doomed request for the default "contacts"
  // entity before permissions have resolved the correct selection.
  useEffect(() => {
    if (visibleEntities.some((e) => e.key === selectedEntity) && !loaded) fetch()
  }, [selectedEntity, loaded, fetch, visibleEntities])

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm)
    setOpen(true)
  }

  const openEdit = (field: FieldRecord) => {
    setEditing(field)
    setForm({
      label: field.label,
      type: field.type,
      choices: (field.options?.choices ?? []).join("\n"),
      is_required: field.is_required,
      is_unique: field.is_unique,
    })
    setOpen(true)
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.label.trim()) return
    if (needsOptions(form.type)) {
      const parsed = form.choices.split("\n").map((c) => c.trim()).filter(Boolean)
      if (parsed.length === 0) {
        addToast({ type: "error", title: t("fields.errors.optionsRequired") })
        return
      }
    }

    setSaving(true)
    const choices = form.choices.split("\n").map((c) => c.trim()).filter(Boolean)
    try {
      if (editing) {
        const result = await update(editing.id, {
          label: form.label.trim(),
          options: needsOptions(editing.type) ? { choices } : null,
          is_required: form.is_required,
          is_unique: form.is_unique,
        })
        if (result) {
          addToast({ type: "success", title: t("fields.savedTitle") })
          setOpen(false)
        }
      } else {
        const result = await create({
          label: form.label.trim(),
          type: form.type,
          options: needsOptions(form.type) ? { choices } : null,
          is_required: form.is_required,
          is_unique: form.is_unique,
        })
        if (result) {
          addToast({ type: "success", title: t("fields.savedTitle") })
          setOpen(false)
        }
      }
    } catch {
      addToast({ type: "error", title: t("fields.errors.invalidPayload") })
    } finally {
      setSaving(false)
    }
  }

  const onDelete = async (field: FieldRecord) => {
    if (!confirm(t("fields.deleteConfirm").replace("{label}", field.label))) return
    try {
      const ok = await remove(field.id)
      if (ok) addToast({ type: "success", title: t("fields.deletedTitle") })
    } catch {
      addToast({ type: "error", title: t("fields.errors.invalidPayload") })
    }
  }

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) return

    const oldIndex = fields.findIndex((f) => f.id === active.id)
    const newIndex = fields.findIndex((f) => f.id === over.id)
    if (oldIndex < 0 || newIndex < 0) return

    const next = arrayMove(fields, oldIndex, newIndex)
    void reorder(next.map((f, i) => ({ id: f.id, display_order: i })))
  }

  const typeLabel = useMemo(
    () => (type: FieldType) => t(`fields.typeLabels.${type}`),
    [t],
  )

  if (visibleEntities.length === 0) return null

  const entityCount = (key: EntityKey): number | null => {
    const s = key === "products" ? productsStore : contactsStore
    return s.loaded ? s.fields.length : null
  }

  return (
    <SettingsBlock
      title={t("fields.title")}
      description={t("fields.subtitle")}
      icon={SlidersHorizontal}
      action={
        canManage ? (
          <Button size="sm" onClick={openCreate}>
            <Plus className="mr-1 size-4" />
            {t("fields.addField")}
          </Button>
        ) : null
      }
    >
      <div className="space-y-4">
        {visibleEntities.length > 1 ? (
          <ToggleGroup
            type="single"
            value={selectedEntity}
            onValueChange={(value) => {
              if (value) setSelectedEntity(value as EntityKey)
            }}
            aria-label={t("fields.entitySelector.label")}
            className="w-full sm:w-auto sm:inline-flex rounded-lg border bg-muted/40 p-1"
          >
            {visibleEntities.map((e) => {
              const count = entityCount(e.key)
              return (
                <ToggleGroupItem
                  key={e.key}
                  value={e.key}
                  className="flex-1 sm:flex-none gap-1.5 rounded-md px-3 text-sm data-[state=on]:bg-background data-[state=on]:shadow-sm"
                >
                  {t(e.labelKey)}
                  {count !== null ? (
                    <span className="text-xs tabular-nums text-muted-foreground">{count}</span>
                  ) : null}
                </ToggleGroupItem>
              )
            })}
          </ToggleGroup>
        ) : null}

        {loading && !loaded ? (
          <ul className="space-y-2" aria-hidden>
            {Array.from({ length: 3 }).map((_, i) => (
              <li
                key={i}
                className="flex items-center gap-3 rounded-md border bg-card p-3"
              >
                <div className="size-4 rounded bg-muted animate-pulse" />
                <div className="flex-1 space-y-2">
                  <div className="h-3.5 w-32 rounded bg-muted animate-pulse" />
                  <div className="h-2.5 w-20 rounded bg-muted/70 animate-pulse" />
                </div>
              </li>
            ))}
          </ul>
        ) : fields.length === 0 ? (
          <div className="rounded-lg border border-dashed py-10 px-6 text-center">
            <p className="text-sm font-medium">{t("fields.emptyTitle")}</p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
              {t("fields.emptyBody")}
            </p>
            {canManage ? (
              <Button size="sm" variant="outline" className="mt-4" onClick={openCreate}>
                <Plus className="size-4 mr-1" />
                {t("fields.addFirstField")}
              </Button>
            ) : null}
          </div>
        ) : (
          <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
            <SortableContext items={fields.map((f) => f.id)} strategy={verticalListSortingStrategy}>
              <ul className="space-y-1.5">
                {fields.map((field) => (
                  <SortableRow
                    key={field.id}
                    field={field}
                    canManage={canManage}
                    typeLabel={typeLabel(field.type)}
                    reorderLabel={t("fields.reorder")}
                    editLabel={t("fields.edit")}
                    deleteLabel={t("fields.delete")}
                    requiredBadge={t("fields.badges.required")}
                    uniqueBadge={t("fields.badges.unique")}
                    onEdit={() => openEdit(field)}
                    onDelete={() => onDelete(field)}
                  />
                ))}
              </ul>
            </SortableContext>
          </DndContext>
        )}
      </div>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing ? t("fields.editTitle") : t("fields.addField")}
            </DialogTitle>
            <DialogDescription>{t("fields.dialogDescription")}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="cf-label">{t("fields.fieldLabel")}</Label>
              <Input
                id="cf-label"
                value={form.label}
                onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="cf-type">{t("fields.fieldType")}</Label>
              <Select
                value={form.type}
                onValueChange={(value) => setForm((f) => ({ ...f, type: value as FieldType }))}
                disabled={!!editing}
              >
                <SelectTrigger id="cf-type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TYPE_OPTIONS.map((type) => (
                    <SelectItem key={type} value={type}>
                      {typeLabel(type)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {editing ? (
                <p className="text-xs text-muted-foreground">
                  {t("fields.typeImmutable")}
                </p>
              ) : null}
            </div>

            {needsOptions(form.type) ? (
              <div className="space-y-2">
                <Label htmlFor="cf-choices">{t("fields.options")}</Label>
                <textarea
                  id="cf-choices"
                  className="w-full min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  value={form.choices}
                  onChange={(e) => setForm((f) => ({ ...f, choices: e.target.value }))}
                  placeholder={t("fields.optionsPlaceholder")}
                />
              </div>
            ) : null}

            <div className="flex items-center justify-between">
              <Label htmlFor="cf-required">{t("fields.required")}</Label>
              <Switch
                id="cf-required"
                checked={form.is_required}
                onCheckedChange={(checked) => setForm((f) => ({ ...f, is_required: checked }))}
              />
            </div>

            <div className="flex items-center justify-between">
              <Label htmlFor="cf-unique">{t("fields.unique")}</Label>
              <Switch
                id="cf-unique"
                checked={form.is_unique}
                onCheckedChange={(checked) => setForm((f) => ({ ...f, is_unique: checked }))}
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={saving}>
                {t("common.cancel")}
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? <Loader2 className="size-4 animate-spin mr-2" /> : null}
                {t("common.save")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </SettingsBlock>
  )
}

interface SortableRowProps {
  field: FieldRecord
  canManage: boolean
  typeLabel: string
  reorderLabel: string
  editLabel: string
  deleteLabel: string
  requiredBadge: string
  uniqueBadge: string
  onEdit: () => void
  onDelete: () => void
}

function SortableRow({
  field,
  canManage,
  typeLabel,
  reorderLabel,
  editLabel,
  deleteLabel,
  requiredBadge,
  uniqueBadge,
  onEdit,
  onDelete,
}: SortableRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: field.id })
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  return (
    <li
      ref={setNodeRef}
      style={style}
      className={cn(
        "group flex items-center gap-3 rounded-md border bg-card px-2.5 py-2 transition-colors",
        isDragging ? "z-10 opacity-80 shadow-md" : "hover:border-foreground/15 hover:bg-muted/40",
      )}
    >
      {canManage ? (
        <button
          type="button"
          className="text-muted-foreground/50 transition-colors hover:text-muted-foreground cursor-grab active:cursor-grabbing"
          {...attributes}
          {...listeners}
          aria-label={reorderLabel}
        >
          <GripVertical className="size-4" />
        </button>
      ) : (
        <span className="w-4 shrink-0" aria-hidden />
      )}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <span className="font-medium truncate">{field.label}</span>
          <Badge variant="secondary" className="text-xs font-normal">
            {typeLabel}
          </Badge>
          {field.is_required ? (
            <span className="text-[0.7rem] uppercase tracking-wide text-muted-foreground">
              {requiredBadge}
            </span>
          ) : null}
          {field.is_unique ? (
            <span className="text-[0.7rem] uppercase tracking-wide text-muted-foreground">
              {uniqueBadge}
            </span>
          ) : null}
        </div>
        <p className="mt-0.5 truncate font-mono text-xs text-muted-foreground/80">{field.key}</p>
      </div>
      {canManage ? (
        <div className="flex items-center gap-0.5 opacity-60 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
          <Button variant="ghost" size="icon" className="size-8" onClick={onEdit} aria-label={editLabel}>
            <Pencil className="size-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="size-8 text-muted-foreground hover:text-destructive"
            onClick={onDelete}
            aria-label={deleteLabel}
          >
            <Trash2 className="size-4" />
          </Button>
        </div>
      ) : null}
    </li>
  )
}
