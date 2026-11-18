"use client"

import { useId, useState } from "react"
import { Loader2 } from "lucide-react"

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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField, ContactFieldType } from "@/lib/api/contact-fields"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

/** Mismo límite que StoreContactFieldRequest. */
const LABEL_MAX = 120

interface CreateContactFieldDialogProps {
  onOpenChange: (open: boolean) => void
  /** Tipos que admite el dato; el primero es el sugerido. */
  types: readonly ContactFieldType[]
  initialLabel: string
  /** Opciones iniciales de un campo de selección (por ejemplo, las de la pregunta). */
  initialChoices?: string[]
  /** Ayuda bajo las opciones; depende de dónde se usan. */
  choicesHint?: string
  onCreated: (field: ContactField) => void
}

function hasChoices(type: ContactFieldType): boolean {
  return type === "select" || type === "multi_select"
}

function parseChoices(text: string): string[] {
  return [...new Set(text.split("\n").map((choice) => choice.trim()).filter(Boolean))]
}

/**
 * Crea un campo personalizado del contacto sin salir del mapeo de un formulario.
 * Se monta abierto: cada apertura arranca con los valores sugeridos.
 */
export function CreateContactFieldDialog({
  onOpenChange,
  types,
  initialLabel,
  initialChoices = [],
  choicesHint,
  onCreated,
}: CreateContactFieldDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const create = useContactFieldsStore((state) => state.create)
  const uid = useId()

  const [label, setLabel] = useState(initialLabel.slice(0, LABEL_MAX))
  const [type, setType] = useState<ContactFieldType>(types[0] ?? "text")
  const [choicesText, setChoicesText] = useState(initialChoices.join("\n"))
  const [saving, setSaving] = useState(false)

  const choices = parseChoices(choicesText)
  const needsChoices = hasChoices(type)
  const canCreate = !saving && label.trim() !== "" && (!needsChoices || choices.length > 0)

  const submit = async () => {
    if (!canCreate) return
    setSaving(true)
    const field = await create({
      label: label.trim(),
      type,
      options: needsChoices ? { choices } : null,
    })
    setSaving(false)
    if (!field) {
      addToast({
        type: "error",
        title: t("flows.fieldCreate.error"),
        description: useContactFieldsStore.getState().error ?? undefined,
      })
      return
    }
    addToast({ type: "success", title: t("flows.fieldCreate.created", { label: field.label }) })
    onCreated(field)
    onOpenChange(false)
  }

  return (
    <Dialog open onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("flows.fieldCreate.title")}</DialogTitle>
          <DialogDescription>{t("flows.fieldCreate.description")}</DialogDescription>
        </DialogHeader>

        <form
          id={`${uid}-form`}
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault()
            void submit()
          }}
        >
          <div className="space-y-1.5">
            <Label htmlFor={`${uid}-label`}>{t("fields.fieldLabel")}</Label>
            <Input
              id={`${uid}-label`}
              value={label}
              maxLength={LABEL_MAX}
              autoFocus
              onChange={(event) => setLabel(event.target.value)}
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor={`${uid}-type`}>{t("fields.fieldType")}</Label>
            <Select value={type} onValueChange={(value) => setType(value as ContactFieldType)} disabled={types.length < 2}>
              <SelectTrigger id={`${uid}-type`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {types.map((option) => (
                  <SelectItem key={option} value={option}>
                    {t(`fields.typeLabels.${option}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {needsChoices ? (
            <div className="space-y-1.5">
              <Label htmlFor={`${uid}-choices`}>{t("fields.options")}</Label>
              <Textarea
                id={`${uid}-choices`}
                value={choicesText}
                rows={4}
                placeholder={t("fields.optionsPlaceholder")}
                aria-invalid={choices.length === 0}
                aria-describedby={`${uid}-choices-hint`}
                onChange={(event) => setChoicesText(event.target.value)}
              />
              <p id={`${uid}-choices-hint`} className="text-xs text-muted-foreground">
                {choices.length === 0 ? t("flows.fieldCreate.choicesRequired") : choicesHint}
              </p>
            </div>
          ) : null}
        </form>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" form={`${uid}-form`} disabled={!canCreate}>
            {saving && <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />}
            {t("flows.fieldCreate.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
