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
import { ChevronDown, ChevronUp, GripVertical, Loader2, Pencil, Plus, SlidersHorizontal, Trash2 } from "lucide-react"

import { cn } from "@/lib/utils"

import { useTranslation } from "@/hooks/useTranslation"
import { useToast } from "@/components/Toast"
import { useAuthStore } from "@/store/useAuthStore"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"
import { useProductFieldsStore } from "@/store/useProductFieldsStore"
import type { ContactField, ContactFieldType, RepeaterSubfield, RepeaterSubfieldType } from "@/lib/api/contact-fields"
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
  "repeater",
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
  repeaterFields: RepeaterSubfield[]
  min_items: number
  max_items: number
}

const emptyForm: FormState = {
  label: "",
  type: "text",
  choices: "",
  is_required: false,
  is_unique: false,
  repeaterFields: [],
  min_items: 0,
  max_items: 10,
}

function needsOptions(type: FieldType): boolean {
  return type === "select" || type === "multi_select"
}

function isRepeater(type: FieldType): boolean {
  return type === "repeater"
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
      repeaterFields: field.type === "repeater" ? (field.options?.fields ?? []) : [],
      min_items: field.options?.min_items ?? 0,
      max_items: field.options?.max_items ?? 10,
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
    if (isRepeater(form.type)) {
      if (form.repeaterFields.filter((field) => field.is_active !== false && field.label.trim()).length === 0) {
        addToast({ type: "error", title: t("fields.repeater.noSubfields") })
        return
      }
      if (form.min_items > form.max_items) {
        addToast({ type: "error", title: t("fields.repeater.invalidLimits") })
        return
      }
    }

    setSaving(true)
    const choices = form.choices.split("\n").map((c) => c.trim()).filter(Boolean)
    try {
      if (editing) {
        const result = await update(editing.id, {
          label: form.label.trim(),
          options: editing.type === "repeater"
            ? { fields: form.repeaterFields, min_items: form.min_items, max_items: form.max_items }
            : needsOptions(editing.type) ? { choices } : null,
          is_required: form.is_required,
          is_unique: isRepeater(form.type) ? false : form.is_unique,
        })
        if (result) {
          addToast({ type: "success", title: t("fields.savedTitle") })
          setOpen(false)
        }
      } else {
        const result = await create({
          label: form.label.trim(),
          type: form.type,
          options: isRepeater(form.type)
            ? { fields: form.repeaterFields, min_items: form.min_items, max_items: form.max_items }
            : needsOptions(form.type) ? { choices } : null,
          is_required: form.is_required,
          is_unique: isRepeater(form.type) ? false : form.is_unique,
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

            {isRepeater(form.type) ? (
              <RepeaterSchemaBuilder
                fields={form.repeaterFields}
                minItems={form.min_items}
                maxItems={form.max_items}
                disabled={saving}
                onChange={(repeaterFields) => setForm((f) => ({ ...f, repeaterFields }))}
                onLimitsChange={(min_items, max_items) => setForm((f) => ({ ...f, min_items, max_items }))}
              />
            ) : null}

            <div className="flex items-center justify-between">
              <Label htmlFor="cf-required">{t("fields.required")}</Label>
              <Switch
                id="cf-required"
                checked={form.is_required}
                onCheckedChange={(checked) => setForm((f) => ({ ...f, is_required: checked }))}
              />
            </div>

            {isRepeater(form.type) ? null : <div className="flex items-center justify-between">
              <Label htmlFor="cf-unique">{t("fields.unique")}</Label>
              <Switch
                id="cf-unique"
                checked={form.is_unique}
                onCheckedChange={(checked) => setForm((f) => ({ ...f, is_unique: checked }))}
              />
            </div>}

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

interface RepeaterSchemaBuilderProps {
  fields: RepeaterSubfield[]
  minItems: number
  maxItems: number
  disabled: boolean
  onChange: (fields: RepeaterSubfield[]) => void
  onLimitsChange: (min: number, max: number) => void
}

function RepeaterSchemaBuilder({ fields, minItems, maxItems, disabled, onChange, onLimitsChange }: RepeaterSchemaBuilderProps) {
  const { t } = useTranslation()
  const activeTypes: RepeaterSubfieldType[] = ["text", "number", "date", "boolean", "select", "email", "url", "phone"]
  const update = (index: number, patch: Partial<RepeaterSubfield>) => {
    onChange(fields.map((field, i) => (i === index ? { ...field, ...patch } : field)))
  }
  const add = () => onChange([...fields, { label: "", type: "text", is_required: false, is_active: true }])
  const move = (index: number, direction: -1 | 1) => {
    const target = index + direction
    if (target < 0 || target >= fields.length) return
    const next = [...fields]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange(next)
  }

  return (
    <div className="space-y-3 rounded-lg border bg-muted/20 p-3">
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium">{t("fields.repeater.title")}</p>
          <p className="text-xs text-muted-foreground">{t("fields.repeater.description")}</p>
        </div>
        <Button type="button" size="sm" variant="outline" onClick={add} disabled={disabled}>
          <Plus className="mr-1 size-4" />{t("fields.repeater.addSubfield")}
        </Button>
      </div>

      {fields.map((field, index) => (
        <div key={field.key ?? `new-${index}`} className={field.is_active === false ? "space-y-2 rounded-md border border-dashed p-2 opacity-60" : "space-y-2 rounded-md border bg-background p-2"}>
          <div className="flex items-center gap-2">
            <Input
              value={field.label}
              placeholder={t("fields.repeater.subfieldLabel")}
              onChange={(e) => update(index, { label: e.target.value })}
              disabled={disabled || field.is_active === false}
              aria-label={t("fields.repeater.subfieldLabel")}
            />
            <Select value={field.type} onValueChange={(type) => update(index, { type: type as RepeaterSubfieldType, options: type === "select" ? field.options : null })} disabled={disabled || !!field.key || field.is_active === false}>
              <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
              <SelectContent>
                {activeTypes.map((type) => <SelectItem key={type} value={type}>{t(`fields.typeLabels.${type}`)}</SelectItem>)}
              </SelectContent>
            </Select>
            <Button type="button" variant="ghost" size="icon" onClick={() => move(index, -1)} disabled={disabled || index === 0} aria-label={t("fields.repeater.moveUp")}><ChevronUp className="size-4" /></Button>
            <Button type="button" variant="ghost" size="icon" onClick={() => move(index, 1)} disabled={disabled || index === fields.length - 1} aria-label={t("fields.repeater.moveDown")}><ChevronDown className="size-4" /></Button>
          </div>
          {field.type === "select" && field.is_active !== false ? (
            <textarea
              className="w-full min-h-16 resize-none rounded-md border border-input bg-background px-3 py-2 text-sm"
              value={field.options?.choices?.join("\n") ?? ""}
              placeholder={t("fields.optionsPlaceholder")}
              onChange={(e) => update(index, { options: { choices: e.target.value.split("\n").map((choice) => choice.trim()).filter(Boolean) } })}
              aria-label={t("fields.options")}
              disabled={disabled}
            />
          ) : null}
          <div className="flex items-center justify-between gap-3">
            <label className="flex items-center gap-2 text-xs text-muted-foreground">
              <Switch checked={Boolean(field.is_required)} onCheckedChange={(checked) => update(index, { is_required: checked })} disabled={disabled || field.is_active === false} />
              {t("fields.required")}
            </label>
            <Button type="button" variant="ghost" size="sm" onClick={() => update(index, { is_active: field.is_active === false })} disabled={disabled}>
              {field.is_active === false ? t("fields.repeater.restoreSubfield") : t("fields.repeater.archiveSubfield")}
            </Button>
          </div>
        </div>
      ))}

      {fields.length === 0 ? <p className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">{t("fields.repeater.noSubfields")}</p> : null}

      <div className="grid grid-cols-2 gap-2">
        <label className="space-y-1 text-xs text-muted-foreground">
          {t("fields.repeater.minItems")}
          <Input type="number" min={0} max={100} value={minItems} onChange={(e) => onLimitsChange(Math.max(0, Math.min(100, Number(e.target.value))), maxItems)} disabled={disabled} />
        </label>
        <label className="space-y-1 text-xs text-muted-foreground">
          {t("fields.repeater.maxItems")}
          <Input type="number" min={0} max={100} value={maxItems} onChange={(e) => onLimitsChange(minItems, Math.max(0, Math.min(100, Number(e.target.value))))} disabled={disabled} />
        </label>
      </div>
    </div>
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
