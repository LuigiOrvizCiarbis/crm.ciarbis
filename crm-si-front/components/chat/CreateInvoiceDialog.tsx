"use client"

import { useEffect, useId, useRef, useState, type FormEvent } from "react"
import { ReceiptText } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { useToast } from "@/components/Toast"
import { createInvoice, getInvoiceSettings, type InvoiceSettingsRecord } from "@/lib/api/invoices"
import { useTranslation } from "@/hooks/useTranslation"

type SendMode = "now" | "draft"

interface CreateInvoiceDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  contact: { id: number; name: string; phone?: string | null }
}

const formatMoney = (cents: number, locale: string) => new Intl.NumberFormat(locale, { style: "currency", currency: "ARS" }).format(cents / 100)

export function CreateInvoiceDialog({ open, onOpenChange, contact }: CreateInvoiceDialogProps) {
  const { addToast } = useToast()
  const { t, language } = useTranslation()
  const tInvoice = (key: string, params?: Record<string, string | number>) => t(`chats.invoiceDialog.${key}`, params)
  const id = useId()
  const conceptRef = useRef<HTMLInputElement>(null)
  const amountRef = useRef<HTMLInputElement>(null)
  const [concept, setConcept] = useState("")
  const [amount, setAmount] = useState("")
  const [mode, setMode] = useState<SendMode>(contact.phone?.trim() ? "now" : "draft")
  const [settings, setSettings] = useState<InvoiceSettingsRecord | null>(null)
  const [approvedInvoiceTemplateIds, setApprovedInvoiceTemplateIds] = useState<number[]>([])
  const [settingsLoading, setSettingsLoading] = useState(true)
  const [settingsError, setSettingsError] = useState("")
  const [fieldError, setFieldError] = useState<"concept" | "amount" | null>(null)
  const [formError, setFormError] = useState("")
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    let active = true
    void getInvoiceSettings()
      .then(({ settings: nextSettings, templates }) => {
        if (active) {
          setSettings(nextSettings)
          setApprovedInvoiceTemplateIds(templates.filter((template) => template.category === "UTILITY" && template.header_format === "DOCUMENT").map((template) => template.id))
        }
      })
      .catch((error: unknown) => {
        if (active) {
          setSettings(null)
          setSettingsError(error instanceof Error ? error.message : t("chats.invoiceDialog.settingsLoadFailed"))
        }
      })
      .finally(() => { if (active) setSettingsLoading(false) })
    return () => { active = false }
  }, [open, t])

  const hasPhone = Boolean(contact.phone?.trim())
  const sendReady = hasPhone && Boolean(settings?.enabled && settings.whatsapp_channel_id && settings.whatsapp_template_id && approvedInvoiceTemplateIds.includes(settings.whatsapp_template_id))
  const amountValue = /^\d+(?:[.,]\d{1,2})?$/.test(amount.trim()) ? Number(amount.trim().replace(",", ".")) : NaN
  const amountCents = Number.isFinite(amountValue) ? Math.round(amountValue * 100) : 0

  function changeOpen(nextOpen: boolean) {
    if (!busy) onOpenChange(nextOpen)
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    const trimmedConcept = concept.trim()
    setFieldError(null)
    setFormError("")
    if (!trimmedConcept) {
      setFieldError("concept")
      setFormError(tInvoice("conceptRequired"))
      conceptRef.current?.focus()
      return
    }
    if (trimmedConcept.length > 500) {
      setFieldError("concept")
      setFormError(tInvoice("conceptTooLong"))
      conceptRef.current?.focus()
      return
    }
    if (amountCents < 1 || amountCents > 100000000000) {
      setFieldError("amount")
      setFormError(tInvoice("amountInvalid"))
      amountRef.current?.focus()
      return
    }
    if (mode === "now" && !sendReady) {
      setFormError(!hasPhone
        ? tInvoice("phoneRequired")
        : settingsLoading ? tInvoice("loadingSettings") : settingsError || tInvoice("settingsRequired"))
      return
    }

    setBusy(true)
    try {
      const invoice = await createInvoice({ contact_id: contact.id, concept: trimmedConcept, amount_cents: amountCents, status: mode === "now" ? "issued" : "draft" })
      addToast({
        type: "success",
        title: tInvoice("created", { number: invoice.number }),
        description: tInvoice(mode === "now" ? "sendQueued" : "draftSaved"),
      })
      setConcept("")
      setAmount("")
      onOpenChange(false)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : tInvoice("createFailed"))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={changeOpen}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl" onOpenAutoFocus={(event) => { event.preventDefault(); conceptRef.current?.focus() }}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><ReceiptText className="size-5 text-primary" /> {t("chats.createInvoice")}</DialogTitle>
          <DialogDescription>{tInvoice("description", { name: contact.name })}</DialogDescription>
        </DialogHeader>

        <form noValidate onSubmit={(event) => void submit(event)} className="space-y-5">
          <div className="rounded-lg border bg-muted/30 px-4 py-3">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{tInvoice("contact")}</p>
            <p className="mt-1 text-sm font-medium">{contact.name}</p>
            <p className="text-xs text-muted-foreground">{hasPhone ? contact.phone : tInvoice("noPhone")}</p>
          </div>

          <div className="space-y-1.5">
            <label htmlFor={`${id}-concept`} className="text-sm font-medium">{tInvoice("concept")}</label>
            <Input id={`${id}-concept`} ref={conceptRef} value={concept} onChange={(event) => { setConcept(event.target.value); setFieldError(null); setFormError("") }} maxLength={501} placeholder={tInvoice("conceptPlaceholder")} aria-invalid={fieldError === "concept"} aria-describedby={fieldError === "concept" ? `${id}-error` : undefined} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor={`${id}-amount`} className="text-sm font-medium">{tInvoice("amount")}</label>
            <Input id={`${id}-amount`} ref={amountRef} value={amount} onChange={(event) => { setAmount(event.target.value); setFieldError(null); setFormError("") }} inputMode="decimal" placeholder={tInvoice("amountPlaceholder")} aria-invalid={fieldError === "amount"} aria-describedby={fieldError === "amount" ? `${id}-error` : undefined} />
            <p className="text-xs text-muted-foreground">{tInvoice("amountHint")}</p>
          </div>

          <div className="space-y-2">
            <p className="text-sm font-medium">{tInvoice("createMode")}</p>
            <div className="grid gap-2 sm:grid-cols-2" role="group" aria-label={tInvoice("createMode")}>
              <button type="button" aria-pressed={mode === "now"} onClick={() => { setMode("now"); setFormError("") }} className={`rounded-lg border px-3 py-3 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${mode === "now" ? "border-primary bg-primary/5" : "hover:bg-muted/50"}`}>
                <span className="block text-sm font-semibold">{tInvoice("sendNow")}</span>
                <span className="mt-1 block text-xs text-muted-foreground">{tInvoice("sendNowHint")}</span>
              </button>
              <button type="button" aria-pressed={mode === "draft"} onClick={() => { setMode("draft"); setFormError("") }} className={`rounded-lg border px-3 py-3 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${mode === "draft" ? "border-primary bg-primary/5" : "hover:bg-muted/50"}`}>
                <span className="block text-sm font-semibold">{tInvoice("saveDraft")}</span>
                <span className="mt-1 block text-xs text-muted-foreground">{tInvoice("saveDraftHint")}</span>
              </button>
            </div>
          </div>

          <div className="flex items-center justify-between gap-3 rounded-lg border border-dashed px-4 py-3 text-sm">
            <span className="text-muted-foreground">{tInvoice("total")}</span>
            <strong className="tabular-nums">{amountCents > 0 ? formatMoney(amountCents, language === "en" ? "en-US" : "es-AR") : "—"}</strong>
          </div>

          {mode === "now" && <p className="text-xs text-muted-foreground">{settingsLoading ? tInvoice("loadingSettings") : !hasPhone ? tInvoice("addPhone") : settingsError ? settingsError : !sendReady ? tInvoice("settingsRequired") : tInvoice("dueHint", { days: settings?.payment_term_days ?? 0 })}</p>}
          {formError && <p id={`${id}-error`} role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => changeOpen(false)} disabled={busy}>{t("chats.cancel")}</Button>
            <Button type="submit" disabled={busy || (mode === "now" && (settingsLoading || !sendReady))} aria-busy={busy}>
              {busy ? tInvoice("creating") : mode === "now" ? tInvoice("issueAndSend") : tInvoice("saveDraft")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
