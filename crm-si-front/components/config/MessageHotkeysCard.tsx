"use client"

import { useEffect, useMemo, useState } from "react"
import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Badge } from "@/components/ui/badge"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Label } from "@/components/ui/label"
import { Zap, Loader2, Pencil, Trash2, Plus } from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { isAdminRole } from "@/lib/permissions"
import {
  createMessageHotkey,
  deleteMessageHotkey,
  getMessageHotkeys,
  updateMessageHotkey,
  type MessageHotkey,
  type MessageHotkeyScope,
} from "@/lib/api/message-hotkeys"


interface FormState {
  trigger: string
  description: string
  content: string
  scope: MessageHotkeyScope
}

const emptyForm: FormState = {
  trigger: "",
  description: "",
  content: "",
  scope: "personal",
}

export function MessageHotkeysCard() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const role = useAuthStore((state) => state.role)
  const permissions = useAuthStore((state) => state.permissions)
  const authUser = useAuthStore((state) => state.user)
  const canManageTenant = useMemo(
    () => isAdminRole(role, permissions),
    [role, permissions],
  )

  const [hotkeys, setHotkeys] = useState<MessageHotkey[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<MessageHotkey | null>(null)
  const [form, setForm] = useState<FormState>(emptyForm)

  useEffect(() => {
    loadHotkeys()
  }, [])

  const loadHotkeys = async () => {
    setLoading(true)
    const data = await getMessageHotkeys()
    setHotkeys(data)
    setLoading(false)
  }

  const openCreate = () => {
    setEditing(null)
    setForm({
      ...emptyForm,
      scope: canManageTenant ? "tenant" : "personal",
    })
    setOpen(true)
  }

  const openEdit = (hotkey: MessageHotkey) => {
    setEditing(hotkey)
    setForm({
      trigger: hotkey.trigger,
      description: hotkey.description ?? "",
      content: hotkey.content,
      scope: hotkey.scope,
    })
    setOpen(true)
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const normalizedTrigger = form.trigger.trim().replace(/^\//, "").toLowerCase()
    if (!normalizedTrigger || !form.content.trim()) return

    setSaving(true)

    const payload = {
      trigger: normalizedTrigger,
      content: form.content.trim(),
      description: form.description.trim() || null,
    }

    const result = editing
      ? await updateMessageHotkey(editing.id, payload)
      : await createMessageHotkey({ ...payload, scope: form.scope })

    setSaving(false)

    if (result.error) {
      addToast({
        title: t("common.error"),
        description: result.error,
        type: "error",
      })
      return
    }

    addToast({
      type: "success",
      title: editing
        ? t("settings.hotkeys.updated")
        : t("settings.hotkeys.created"),
    })
    setOpen(false)
    setEditing(null)
    setForm(emptyForm)
    loadHotkeys()
  }

  const handleDelete = async (hotkey: MessageHotkey) => {
    if (!confirm(t("settings.hotkeys.confirmDelete"))) return

    const { error } = await deleteMessageHotkey(hotkey.id)
    if (error) {
      addToast({ title: t("common.error"), description: error, type: "error" })
      return
    }
    addToast({ type: "success", title: t("settings.hotkeys.deleted") })
    loadHotkeys()
  }

  const canManage = (hotkey: MessageHotkey) => {
    if (hotkey.scope === "tenant") return canManageTenant
    return hotkey.user_id === authUser?.id
  }

  const tenantHotkeys = hotkeys.filter((h) => h.scope === "tenant")
  const personalHotkeys = hotkeys.filter((h) => h.scope === "personal")

  return (
    <SettingsBlock
      title={t("settings.hotkeys.title")}
      icon={Zap}
      measure="prose"
      action={
        <Button size="sm" onClick={openCreate}>
          <Plus className="mr-1 size-4" />
          {t("settings.hotkeys.new")}
        </Button>
      }
    >
      <div className="space-y-5">
        {loading ? (
          <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
        ) : hotkeys.length === 0 ? (
          <p className="py-2 text-sm text-muted-foreground">
            {t("settings.hotkeys.empty")}
          </p>
        ) : (
          <>
            {tenantHotkeys.length > 0 && (
              <HotkeySection
                title={t("settings.hotkeys.sectionTenant")}
                hotkeys={tenantHotkeys}
                onEdit={openEdit}
                onDelete={handleDelete}
                canManage={canManage}
              />
            )}
            {personalHotkeys.length > 0 && (
              <HotkeySection
                title={t("settings.hotkeys.sectionPersonal")}
                hotkeys={personalHotkeys}
                onEdit={openEdit}
                onDelete={handleDelete}
                canManage={canManage}
              />
            )}
          </>
        )}

        <p className="text-xs text-muted-foreground">
          {t("settings.hotkeys.variablesHelp")}
        </p>
      </div>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {editing ? t("settings.hotkeys.editTitle") : t("settings.hotkeys.newTitle")}
            </DialogTitle>
            <DialogDescription>{t("settings.hotkeys.variablesHelp")}</DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="hotkey-trigger">{t("settings.hotkeys.trigger")}</Label>
              <div className="flex items-center gap-2">
                <span className="text-muted-foreground font-mono">/</span>
                <Input
                  id="hotkey-trigger"
                  value={form.trigger}
                  onChange={(e) =>
                    setForm((f) => ({
                      ...f,
                      trigger: e.target.value.replace(/^\//, "").toLowerCase(),
                    }))
                  }
                  placeholder="hola"
                  required
                  pattern="[a-z0-9_-]+"
                  maxLength={64}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="hotkey-description">{t("settings.hotkeys.description")}</Label>
              <Input
                id="hotkey-description"
                value={form.description}
                onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                placeholder={t("settings.hotkeys.descriptionPlaceholder")}
                maxLength={255}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="hotkey-content">{t("settings.hotkeys.content")}</Label>
              <Textarea
                id="hotkey-content"
                value={form.content}
                onChange={(e) => setForm((f) => ({ ...f, content: e.target.value }))}
                rows={5}
                required
                maxLength={4000}
                placeholder={t("settings.hotkeys.contentPlaceholder")}
              />
            </div>

            {!editing && canManageTenant && (
              <div className="space-y-2">
                <Label>{t("settings.hotkeys.scopeLabel")}</Label>
                <RadioGroup
                  value={form.scope}
                  onValueChange={(value) => setForm((f) => ({ ...f, scope: value as MessageHotkeyScope }))}
                  className="flex gap-4"
                >
                  <div className="flex items-center gap-2">
                    <RadioGroupItem id="scope-tenant" value="tenant" />
                    <Label htmlFor="scope-tenant" className="font-normal">
                      {t("settings.hotkeys.scope.tenant")}
                    </Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioGroupItem id="scope-personal" value="personal" />
                    <Label htmlFor="scope-personal" className="font-normal">
                      {t("settings.hotkeys.scope.personal")}
                    </Label>
                  </div>
                </RadioGroup>
              </div>
            )}

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={saving}>
                {t("common.cancel")}
              </Button>
              <Button type="submit" disabled={saving}>
                {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : t("common.save")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </SettingsBlock>
  )
}

interface HotkeySectionProps {
  title: string
  hotkeys: MessageHotkey[]
  onEdit: (hotkey: MessageHotkey) => void
  onDelete: (hotkey: MessageHotkey) => void
  canManage: (hotkey: MessageHotkey) => boolean
}

function HotkeySection({ title, hotkeys, onEdit, onDelete, canManage }: HotkeySectionProps) {
  return (
    <div className="space-y-2">
      <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
        {title}
      </p>
      <div className="divide-y divide-border border-y border-border">
        {hotkeys.map((hotkey) => (
          <div
            key={hotkey.id}
            className="flex items-start justify-between gap-3 py-3"
          >
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="font-mono text-xs">
                  /{hotkey.trigger}
                </Badge>
                {hotkey.description && (
                  <span className="truncate text-sm font-medium">{hotkey.description}</span>
                )}
              </div>
              <p className="mt-1 line-clamp-2 whitespace-pre-wrap text-xs text-muted-foreground">
                {hotkey.content}
              </p>
            </div>
            {canManage(hotkey) && (
              <div className="flex shrink-0 gap-1">
                <Button variant="ghost" size="icon" className="size-8" onClick={() => onEdit(hotkey)}>
                  <Pencil className="size-4" />
                </Button>
                <Button variant="ghost" size="icon" className="size-8" onClick={() => onDelete(hotkey)}>
                  <Trash2 className="size-4" />
                </Button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
