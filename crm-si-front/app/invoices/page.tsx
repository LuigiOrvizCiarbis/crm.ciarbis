"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { ArrowDownToLine, Building2, Check, ChevronDown, CircleAlert, Clock3, FileCheck2, FileText, MessageCircle, Plus, RefreshCw, Repeat2, Search, Settings2, Wallet } from "lucide-react"
import { SidebarLayout } from "@/components/SidebarLayout"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Badge } from "@/components/ui/badge"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Switch } from "@/components/ui/switch"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useToast } from "@/components/Toast"
import { getContacts, type Contact } from "@/lib/api/contacts"
import { getChannels } from "@/lib/api/channels"
import { ChannelType } from "@/data/enums"
import { useAuthStore } from "@/store/useAuthStore"
import { getAuthToken, workspaceHeaders } from "@/lib/api/auth-token"
import { createInvoice, createRecurrence, getInvoice, getInvoiceSettings, issueInvoice, listInvoiceRecurrences, listInvoices, provisionInvoiceTemplates, recordInvoicePayment, recurrenceAction, resendInvoice, reverseInvoicePayment, saveInvoiceSettings, updateInvoice, updateRecurrence, voidInvoice, type InvoiceCollectionStatus, type InvoiceRecord, type InvoiceRecurrenceRecord, type InvoiceSettingsRecord, type InvoiceSummary, type InvoiceTemplateProvisioningRecord } from "@/lib/api/invoices"

const money = (cents: number) => new Intl.NumberFormat("es-AR", { style: "currency", currency: "ARS" }).format(cents / 100)
const date = (value?: string | null) => value ? new Intl.DateTimeFormat("es-AR", { dateStyle: "medium" }).format(new Date(`${value.slice(0, 10)}T12:00:00`)) : "—"
const collectionStatusLabels: Record<InvoiceCollectionStatus, string> = {
  pending: "Pendiente",
  overdue: "Impago",
  partial: "Pago parcial",
  partial_overdue: "Pago parcial · vencido",
  paid: "Pagado",
}
const collectionStatusLabel = (status?: InvoiceCollectionStatus | null) => status ? collectionStatusLabels[status] : "—"
const collectionStatusVariant = (status?: InvoiceCollectionStatus | null) => status === "paid" ? "secondary" : status === "overdue" || status === "partial_overdue" ? "destructive" : status === "partial" ? "default" : "outline"
const deliveryStatusLabel = (status: string) => status === "delivered" ? "WhatsApp entregado" : status === "accepted" ? "WhatsApp enviado" : status === "failed" ? "Falló el envío" : status === "unknown" ? "Entrega sin confirmar" : "Envío pendiente de confirmación"

export default function InvoicesPage() {
  const { addToast } = useToast()
  const { permissions, role } = useAuthStore()
  const isOwner = role?.is_owner === true
  const canManage = isOwner || permissions.includes("invoices.manage")
  const canConfigure = isOwner || permissions.includes("invoices.configure")
  const canCreateTemplates = isOwner || permissions.includes("templates.create")
  const canPay = isOwner || permissions.includes("invoices.payments")
  const [invoices, setInvoices] = useState<InvoiceRecord[]>([])
  const [invoiceSummary, setInvoiceSummary] = useState<InvoiceSummary>({ outstanding_cents: 0, overdue_balance_cents: 0, overdue_count: 0 })
  const [recurrences, setRecurrences] = useState<InvoiceRecurrenceRecord[]>([])
  const [contacts, setContacts] = useState<Contact[]>([])
  const [settings, setSettings] = useState<InvoiceSettingsRecord | null>(null)
  const [templateProvisioning, setTemplateProvisioning] = useState<InvoiceTemplateProvisioningRecord | null>(null)
  const [provisioningBusy, setProvisioningBusy] = useState(false)
  const [templates, setTemplates] = useState<Array<{ id: number; name: string; header_format: string | null; parameters: string[] }>>([])
  const [channels, setChannels] = useState<Array<{ id: number; name: string; type: number; status: string }>>([])
  const [loading, setLoading] = useState(true)
  const [invoicePage, setInvoicePage] = useState(1)
  const [invoicePages, setInvoicePages] = useState(1)
  const [busy, setBusy] = useState(false)
  const [showCreate, setShowCreate] = useState(false)
  const [showSettings, setShowSettings] = useState(false)
  const [settingsFormError, setSettingsFormError] = useState("")
  const [recurrenceToCancel, setRecurrenceToCancel] = useState<number | null>(null)
  const [recurrenceToEdit, setRecurrenceToEdit] = useState<InvoiceRecurrenceRecord | null>(null)
  const [recurrenceEdit, setRecurrenceEdit] = useState({ concept: "", amount: "", interval_count: "1", interval_unit: "months", payment_term_days: "10", ends_on: "" })
  const [selectedInvoice, setSelectedInvoice] = useState<InvoiceRecord | null>(null)
  const [editingInvoice, setEditingInvoice] = useState(false)
  const [editForm, setEditForm] = useState({ concept: "", amount: "", scheduled_at: "" })
  const [paymentToReverse, setPaymentToReverse] = useState<number | null>(null)
  const [paymentAmount, setPaymentAmount] = useState("")
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10))
  const [paymentMethod, setPaymentMethod] = useState("")
  const [correctionReason, setCorrectionReason] = useState("")
  const [kind, setKind] = useState<"once" | "repeat">("once")
  const [search, setSearch] = useState("")
  const [invoiceSearch, setInvoiceSearch] = useState("")
  const [collectionStatusFilter, setCollectionStatusFilter] = useState<InvoiceCollectionStatus | "all">("all")
  const loadSequence = useRef(0)
  const [form, setForm] = useState({ contact_id: "", concept: "", amount: "", send: "draft", scheduled_at: "", interval_unit: "months", interval_count: "1", starts_on: new Date().toISOString().slice(0, 10), ends_on: "", payment_term_days: "10" })

  const reload = useCallback(async () => {
    const sequence = ++loadSequence.current
    setLoading(true)
    try {
      const [invoiceResult, recurrenceRows, contactRows, config, channelRows] = await Promise.all([listInvoices(invoicePage, invoiceSearch, collectionStatusFilter), listInvoiceRecurrences(), getContacts({ per_page: 100 }), getInvoiceSettings(), getChannels()])
      if (sequence !== loadSequence.current) return
      setInvoices(invoiceResult.rows); setInvoicePages(invoiceResult.pages); setInvoiceSummary(invoiceResult.summary); setRecurrences(recurrenceRows); setContacts(contactRows)
      setSettings(config.settings); setTemplates(config.templates); setTemplateProvisioning(config.template_provisioning)
      setChannels(channelRows.filter((channel) => channel.type === ChannelType.WHATSAPP) as typeof channelRows)
    } catch (error) {
      if (sequence !== loadSequence.current) return
      addToast({ type: "error", title: "No se pudo cargar Invoices", description: error instanceof Error ? error.message : "Intentá de nuevo." })
    } finally { if (sequence === loadSequence.current) setLoading(false) }
  }, [addToast, collectionStatusFilter, invoicePage, invoiceSearch])

  useEffect(() => {
    const timeout = window.setTimeout(() => { void reload() }, 0)

    return () => window.clearTimeout(timeout)
  }, [reload])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setInvoicePage(1)
      setInvoiceSearch(search.trim())
    }, 300)

    return () => window.clearTimeout(timeout)
  }, [search])

  const isTemplateProvisioningActive = Boolean(templateProvisioning && ["queued", "creating", "pending_review"].includes(templateProvisioning.state))
  useEffect(() => {
    if (!showSettings || !settings?.whatsapp_channel_id || !isTemplateProvisioningActive) return
    let active = true
    const timer = window.setInterval(async () => {
      try {
        const config = await getInvoiceSettings()
        if (!active || config.settings.whatsapp_channel_id !== settings.whatsapp_channel_id) return
        setTemplateProvisioning(config.template_provisioning)
        setTemplates(config.templates)
        setSettings((current) => {
          if (!current || current.whatsapp_channel_id !== settings.whatsapp_channel_id) return current
          const invoiceId = config.template_provisioning?.invoice?.status === "APPROVED" ? config.template_provisioning.invoice.id : null
          const reminderId = config.template_provisioning?.reminder?.status === "APPROVED" ? config.template_provisioning.reminder.id : null
          return { ...current, whatsapp_template_id: current.whatsapp_template_id ?? invoiceId, reminder_template_id: current.reminder_template_id ?? reminderId }
        })
      } catch { /* a later poll can recover transient network errors */ }
    }, 4000)
    return () => { active = false; window.clearInterval(timer) }
  }, [showSettings, settings?.whatsapp_channel_id, isTemplateProvisioningActive])

  const visibleInvoices = invoices

  async function submitCreate(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true)
    try {
      const cents = Math.round(Number(form.amount.replace(",", ".")) * 100)
      if (!Number.isFinite(cents) || cents < 1) throw new Error("Ingresá un importe válido.")
      if (kind === "once") {
        const status = form.send === "now" ? "issued" : form.send === "schedule" ? "scheduled" : "draft"
        await createInvoice({ contact_id: Number(form.contact_id), concept: form.concept, amount_cents: cents, status, ...(status === "scheduled" ? { scheduled_at: new Date(form.scheduled_at).toISOString() } : {}) })
      } else {
        await createRecurrence({ contact_id: Number(form.contact_id), concept: form.concept, amount_cents: cents, interval_unit: form.interval_unit, interval_count: Number(form.interval_count), starts_on: form.starts_on, ends_on: form.ends_on || null, payment_term_days: Number(form.payment_term_days) })
      }
      setShowCreate(false); setForm((current) => ({ ...current, contact_id: "", concept: "", amount: "" }))
      addToast({ type: "success", title: kind === "once" ? "Cobro creado" : "Recurrencia guardada", description: kind === "repeat" ? "Quedó en borrador para que revises el calendario antes de activarla." : undefined })
      await reload()
    } catch (error) { addToast({ type: "error", title: "No se pudo guardar", description: error instanceof Error ? error.message : "Revisá los datos e intentá de nuevo." }) }
    finally { setBusy(false) }
  }

  async function activateRecurrence(id: number) {
    setBusy(true)
    try { await recurrenceAction(id, "activate"); addToast({ type: "success", title: "Recurrencia activada" }); await reload() }
    catch (error) { addToast({ type: "error", title: "No se pudo activar", description: error instanceof Error ? error.message : "Intentá de nuevo." }) }
    finally { setBusy(false) }
  }

  async function changeRecurrenceState(id: number, action: "pause" | "resume" | "cancel") {
    setBusy(true)
    try {
      await recurrenceAction(id, action)
      addToast({ type: "success", title: action === "pause" ? "Recurrencia pausada" : action === "resume" ? "Recurrencia reanudada" : "Recurrencia cancelada" })
      setRecurrenceToCancel(null)
      await reload()
    } catch (error) { addToast({ type: "error", title: "No se pudo actualizar la recurrencia", description: error instanceof Error ? error.message : "Intentá de nuevo." }) }
    finally { setBusy(false) }
  }

  async function saveRecurrenceEdit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!recurrenceToEdit) return
    setBusy(true)
    try {
      const amount_cents = Math.round(Number(recurrenceEdit.amount.replace(",", ".")) * 100)
      await updateRecurrence(recurrenceToEdit.id, { concept: recurrenceEdit.concept, amount_cents, interval_count: Number(recurrenceEdit.interval_count), interval_unit: recurrenceEdit.interval_unit, payment_term_days: Number(recurrenceEdit.payment_term_days), ends_on: recurrenceEdit.ends_on || null })
      setRecurrenceToEdit(null); addToast({ type: "success", title: "Recurrencia actualizada" }); await reload()
    } catch (error) { addToast({ type: "error", title: "No se pudo actualizar la recurrencia", description: error instanceof Error ? error.message : "Revisá los datos." }) }
    finally { setBusy(false) }
  }

  function updateSettingsDraft(patch: Partial<InvoiceSettingsRecord>) {
    setSettingsFormError("")
    setSettings((current) => current ? { ...current, ...patch } : current)
  }

  async function updateSettings(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!settings) return
    if (!Number.isInteger(settings.payment_term_days) || settings.payment_term_days < 0 || settings.payment_term_days > 365) {
      setSettingsFormError("Los días para pagar deben estar entre 0 y 365."); return
    }
    if (!Number.isInteger(settings.send_hour) || settings.send_hour < 0 || settings.send_hour > 23) {
      setSettingsFormError("La hora de envío debe estar entre 0 y 23."); return
    }
    try { new Intl.DateTimeFormat("es-AR", { timeZone: settings.timezone }) }
    catch { setSettingsFormError("Ingresá una zona horaria válida, por ejemplo America/Argentina/Buenos_Aires."); return }
    if (settings.enabled && (!settings.business_name?.trim() || !settings.whatsapp_channel_id || !settings.whatsapp_template_id || !settings.reminder_template_id)) {
      setSettingsFormError("Para activar los envíos, completá el nombre del negocio y elegí el canal y las dos plantillas aprobadas."); return
    }
    setSettingsFormError(""); setBusy(true)
    try { await saveInvoiceSettings(settings); addToast({ type: "success", title: "Configuración guardada" }); setShowSettings(false); await reload() }
    catch (error) { addToast({ type: "error", title: "No se pudo guardar la configuración", description: error instanceof Error ? error.message : "Revisá las plantillas y el canal." }) }
    finally { setBusy(false) }
  }

  async function startTemplateProvisioning() {
    const channelId = settings?.whatsapp_channel_id
    if (!channelId) return
    setProvisioningBusy(true)
    try {
      const provisioning = await provisionInvoiceTemplates(channelId)
      setTemplateProvisioning(provisioning)
      addToast({ type: "success", title: "Plantillas solicitadas", description: "Meta las revisará antes de que se puedan usar. Los envíos automáticos siguen bajo tu control." })
    } catch (error) {
      addToast({ type: "error", title: "No se pudieron crear las plantillas", description: error instanceof Error ? error.message : "Verificá la conexión de WhatsApp y tus permisos." })
    } finally { setProvisioningBusy(false) }
  }

  async function downloadPdf(id: number) {
    const token = getAuthToken(); if (!token) return
    const response = await fetch(`/api/invoices/${id}/pdf`, { headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() } })
    if (!response.ok) { addToast({ type: "error", title: "No se pudo descargar el PDF" }); return }
    const blob = await response.blob(); const url = URL.createObjectURL(blob); const link = document.createElement("a"); link.href = url; link.download = `${invoices.find((invoice) => invoice.id === id)?.number ?? "invoice"}.pdf`; link.click(); URL.revokeObjectURL(url)
  }

  async function openInvoice(id: number) {
    try { const invoice = await getInvoice(id); setSelectedInvoice(invoice); setPaymentAmount(""); setCorrectionReason(""); setPaymentToReverse(null); setEditingInvoice(false); setEditForm({ concept: invoice.concept, amount: (invoice.amount_cents / 100).toFixed(2), scheduled_at: invoice.scheduled_at ? new Date(invoice.scheduled_at).toISOString().slice(0, 16) : "" }) }
    catch (error) { addToast({ type: "error", title: "No se pudo abrir el cobro", description: error instanceof Error ? error.message : "Intentá de nuevo." }) }
  }

  async function refreshSelected() {
    if (!selectedInvoice) return
    await reload()
    setSelectedInvoice(await getInvoice(selectedInvoice.id))
  }

  async function saveInvoiceEdits(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedInvoice) return
    setBusy(true)
    try {
      const amount_cents = Math.round(Number(editForm.amount.replace(",", ".")) * 100)
      await updateInvoice(selectedInvoice.id, { concept: editForm.concept, amount_cents, ...(selectedInvoice.status === "scheduled" ? { scheduled_at: new Date(editForm.scheduled_at).toISOString() } : {}) })
      addToast({ type: "success", title: "Invoice actualizado" }); setEditingInvoice(false); await refreshSelected()
    } catch (error) { addToast({ type: "error", title: "No se pudo actualizar", description: error instanceof Error ? error.message : "Revisá los datos." }) }
    finally { setBusy(false) }
  }

  async function submitPayment(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedInvoice) return
    setBusy(true)
    try {
      const amount_cents = Math.round(Number(paymentAmount.replace(",", ".")) * 100)
      await recordInvoicePayment(selectedInvoice.id, { amount_cents, paid_on: paymentDate, method: paymentMethod || undefined })
      addToast({ type: "success", title: "Pago registrado" }); await refreshSelected(); setPaymentAmount("")
    } catch (error) { addToast({ type: "error", title: "No se pudo registrar el pago", description: error instanceof Error ? error.message : "Revisá el importe y el saldo." }) }
    finally { setBusy(false) }
  }

  async function runInvoiceAction(action: "issue" | "resend" | "void") {
    if (!selectedInvoice) return
    setBusy(true)
    try {
      if (action === "issue") await issueInvoice(selectedInvoice.id)
      else if (action === "resend") await resendInvoice(selectedInvoice.id)
      else await voidInvoice(selectedInvoice.id, correctionReason)
      addToast({ type: "success", title: action === "issue" ? "Invoice emitido" : action === "resend" ? "Invoice reenviado" : "Invoice anulado" })
      await refreshSelected(); setCorrectionReason("")
    } catch (error) { addToast({ type: "error", title: "No se pudo completar la acción", description: error instanceof Error ? error.message : "Revisá el estado del invoice." }) }
    finally { setBusy(false) }
  }

  async function reversePayment(paymentId: number) {
    if (!selectedInvoice || correctionReason.trim().length < 3) return
    setBusy(true)
    try { await reverseInvoicePayment(selectedInvoice.id, paymentId, correctionReason); addToast({ type: "success", title: "Pago revertido" }); setCorrectionReason(""); setPaymentToReverse(null); await refreshSelected() }
    catch (error) { addToast({ type: "error", title: "No se pudo revertir el pago", description: error instanceof Error ? error.message : "Intentá de nuevo." }) }
    finally { setBusy(false) }
  }

  const templatesForChannel = templates
  return <SidebarLayout>
    <main className="min-h-full bg-gradient-to-b from-muted/35 via-background to-background">
      <div className="mx-auto max-w-7xl space-y-7 px-4 py-7 md:px-8 md:py-10">
        <header className="flex flex-col justify-between gap-5 md:flex-row md:items-end">
          <div className="max-w-2xl space-y-2">
            <div className="flex items-center gap-2 text-sm font-medium text-primary"><span className="grid size-8 place-items-center rounded-xl bg-primary/10"><FileText className="size-4" /></span>Gestión de cobros</div>
            <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">Invoices</h1>
            <p className="text-sm text-muted-foreground md:text-base">Enviá solicitudes de pago y seguí cada saldo desde el CRM.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            {canConfigure && <Button variant="outline" onClick={() => { setSettingsFormError(""); setShowSettings((shown) => !shown) }}><Settings2 className="mr-2 size-4" /> Configurar</Button>}
            {canManage && <Button onClick={() => setShowCreate(true)}><Plus className="mr-2 size-4" /> Nuevo cobro</Button>}
          </div>
        </header>

        {settings && !settings.enabled && <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><CircleAlert className="mt-0.5 size-5 shrink-0 text-amber-600" /><div><p className="font-medium">Configurá WhatsApp para habilitar los envíos automáticos</p><p className="mt-1 text-muted-foreground">Podés guardar borradores ahora; la emisión queda deshabilitada hasta elegir plantillas aprobadas.</p></div></div>}

        {canConfigure && showSettings && settings && <Card className="overflow-hidden border-border/70 shadow-sm">
          <CardHeader className="flex flex-row items-start gap-4 border-b bg-muted/20 px-5 py-5 sm:px-7">
            <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Settings2 className="size-5" /></span>
            <div className="min-w-0 space-y-1">
              <CardTitle className="text-lg">Configuración de cobros</CardTitle>
              <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">Definí cómo se emiten los cobros, desde qué canal se envían y qué datos aparecen en el comprobante.</p>
            </div>
          </CardHeader>
          <CardContent className="px-5 py-5 sm:px-7 sm:py-6">
            <form onSubmit={updateSettings} noValidate className="space-y-6">
              {settingsFormError && <div role="alert" className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"><CircleAlert className="mt-0.5 size-4 shrink-0" /><p>{settingsFormError}</p></div>}
              <section aria-labelledby="invoice-business-heading" className="space-y-4">
                <div className="flex items-center gap-3">
                  <span className="grid size-9 place-items-center rounded-lg bg-muted text-muted-foreground"><Building2 className="size-4" /></span>
                  <div><h3 id="invoice-business-heading" className="text-sm font-semibold">Datos del negocio y envío</h3><p className="text-xs text-muted-foreground">Identidad del comprobante y plantillas de WhatsApp.</p></div>
                </div>
                <div className="grid gap-x-5 gap-y-4 md:grid-cols-2">
                  <label htmlFor="invoice-business-name" className="block space-y-1.5 text-sm font-medium">Nombre del negocio<Input id="invoice-business-name" required value={settings.business_name ?? ""} onChange={(event) => updateSettingsDraft({ business_name: event.target.value })} placeholder="Nombre que aparecerá en el comprobante" className="mt-1 w-full" /></label>
                  <div className="block space-y-1.5 text-sm font-medium"><span id="invoice-channel-label" className="block">Canal de WhatsApp</span><Select value={settings.whatsapp_channel_id ? String(settings.whatsapp_channel_id) : "none"} onValueChange={async (value) => { const channelId = value === "none" ? null : Number(value); updateSettingsDraft({ whatsapp_channel_id: channelId, whatsapp_template_id: null, reminder_template_id: null }); setTemplates([]); setTemplateProvisioning(null); if (channelId) { try { const token = getAuthToken(); const response = await fetch(`/api/channels/${channelId}/templates?status=all`, { headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() } }); if (!response.ok) throw new Error("No se pudieron cargar las plantillas del canal."); const data = await response.json().catch(() => []); setTemplates((Array.isArray(data) ? data : data.data ?? []).filter((item: { status: string }) => item.status.toUpperCase() === "APPROVED").map((item: { id: number; name: string; components?: Array<{ type: string; format?: string }>; expected_body_parameters?: string[] }) => ({ id: item.id, name: item.name, header_format: item.components?.find((part) => part.type.toUpperCase() === "HEADER")?.format ?? null, parameters: item.expected_body_parameters ?? [] }))) } catch (error) { addToast({ type: "error", title: "No se pudieron cargar las plantillas", description: error instanceof Error ? error.message : "Intentá de nuevo." }) } } }}><SelectTrigger id="invoice-channel" aria-labelledby="invoice-channel-label" className="mt-1 w-full"><SelectValue placeholder="Seleccioná un canal" /></SelectTrigger><SelectContent><SelectItem value="none">Sin canal seleccionado</SelectItem>{channels.map((channel) => <SelectItem key={channel.id} value={String(channel.id)}>{channel.name}</SelectItem>)}</SelectContent></Select></div>
                  <div className="block space-y-1.5 text-sm font-medium"><span id="invoice-template-label" className="block">Plantilla del cobro</span><Select value={settings.whatsapp_template_id ? String(settings.whatsapp_template_id) : "none"} onValueChange={(value) => updateSettingsDraft({ whatsapp_template_id: value === "none" ? null : Number(value) })}><SelectTrigger id="invoice-template" aria-labelledby="invoice-template-label" className="mt-1 w-full"><SelectValue placeholder="Elegí una plantilla aprobada" /></SelectTrigger><SelectContent><SelectItem value="none">Sin plantilla seleccionada</SelectItem>{templatesForChannel.filter((template) => template.header_format === "DOCUMENT").map((template) => <SelectItem key={template.id} value={String(template.id)}>{template.name}</SelectItem>)}</SelectContent></Select><p className="text-xs font-normal text-muted-foreground">Usá una plantilla aprobada que incluya un documento.</p></div>
                  <div className="block space-y-1.5 text-sm font-medium"><span id="invoice-reminder-template-label" className="block">Plantilla de recordatorio</span><Select value={settings.reminder_template_id ? String(settings.reminder_template_id) : "none"} onValueChange={(value) => updateSettingsDraft({ reminder_template_id: value === "none" ? null : Number(value) })}><SelectTrigger id="invoice-reminder-template" aria-labelledby="invoice-reminder-template-label" className="mt-1 w-full"><SelectValue placeholder="Elegí una plantilla aprobada" /></SelectTrigger><SelectContent><SelectItem value="none">Sin plantilla seleccionada</SelectItem>{templatesForChannel.filter((template) => !template.header_format).map((template) => <SelectItem key={template.id} value={String(template.id)}>{template.name}</SelectItem>)}</SelectContent></Select><p className="text-xs font-normal text-muted-foreground">Usá una plantilla aprobada sin archivo adjunto.</p></div>
                </div>
                {settings.whatsapp_channel_id && <div className="space-y-3 rounded-xl border bg-muted/15 p-4">
                  <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><div><p className="text-sm font-semibold">Plantillas de cobro para WhatsApp</p><p className="mt-1 max-w-2xl text-xs leading-relaxed text-muted-foreground">Creamos el cobro con PDF de ejemplo y el recordatorio. Meta debe aprobarlos antes de que puedas usarlos; esto no activa los envíos automáticos.</p></div><Button type="button" variant="outline" disabled={!canCreateTemplates || provisioningBusy || ["ready", "rejected"].includes(templateProvisioning?.state ?? "") || !channels.find((channel) => channel.id === settings.whatsapp_channel_id && channel.status === "active")} onClick={() => void startTemplateProvisioning()} className="shrink-0"><MessageCircle className="mr-2 size-4" />{provisioningBusy ? "Solicitando…" : templateProvisioning?.state === "partial" ? "Reintentar faltantes" : "Crear plantillas"}</Button></div>
                  {!canCreateTemplates && <p className="text-xs text-muted-foreground">Necesitás permiso para crear plantillas de WhatsApp.</p>}
                  {templateProvisioning && templateProvisioning.channel_id === settings.whatsapp_channel_id && <div role="status" className="grid gap-2 sm:grid-cols-2">{([['invoice', 'Cobro'], ['reminder', 'Recordatorio']] as const).map(([roleKey, label]) => { const template = templateProvisioning[roleKey]; const error = templateProvisioning[`${roleKey}_error` as "invoice_error" | "reminder_error"]; const status = template?.status ?? (error ? "ERROR" : ["queued", "creating"].includes(templateProvisioning.state) ? "CREATING" : "PENDING"); const description = status === "CREATING" ? (templateProvisioning.state === "queued" ? "En cola para crear" : "Creando plantilla") : status === "APPROVED" ? "Aprobada y seleccionada" : status === "REJECTED" ? `Rechazada${template?.rejected_reason ? `: ${template.rejected_reason}` : " por Meta"}` : status === "ERROR" ? error : status === "PENDING" ? "En revisión de Meta" : status.replaceAll("_", " "); return <div key={roleKey} className="rounded-lg border bg-background p-3"><div className="flex items-center justify-between gap-2"><p className="text-xs font-medium">{label}</p><Badge variant={status === "APPROVED" ? "secondary" : status === "REJECTED" || status === "ERROR" ? "destructive" : "outline"}>{status === "APPROVED" ? "Aprobada" : status === "REJECTED" ? "Rechazada" : status === "ERROR" ? "Error" : status === "CREATING" ? "Creando" : "En revisión"}</Badge></div><p className="mt-1 break-words text-xs text-muted-foreground">{description}</p></div> })}</div>}
                  {templateProvisioning?.state === "ready" && <p className="text-xs text-muted-foreground">Podés activar los envíos automáticos cuando quieras desde el control de abajo.</p>}
                </div>}
              </section>

              <section aria-labelledby="invoice-schedule-heading" className="space-y-4 border-t pt-6">
                <div className="flex items-center gap-3">
                  <span className="grid size-9 place-items-center rounded-lg bg-muted text-muted-foreground"><Clock3 className="size-4" /></span>
                  <div><h3 id="invoice-schedule-heading" className="text-sm font-semibold">Plazos y horario</h3><p className="text-xs text-muted-foreground">Los horarios se interpretan en la zona horaria elegida.</p></div>
                </div>
                <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                  <label htmlFor="invoice-payment-days" className="block space-y-1.5 text-sm font-medium">Días para pagar<Input id="invoice-payment-days" type="number" min="0" max="365" value={settings.payment_term_days} onChange={(event) => updateSettingsDraft({ payment_term_days: Number(event.target.value) })} className="mt-1 w-full" /><span className="block text-xs font-normal text-muted-foreground">Plazo desde la fecha de emisión.</span></label>
                  <label htmlFor="invoice-send-hour" className="block space-y-1.5 text-sm font-medium">Hora de envío<Input id="invoice-send-hour" type="number" min="0" max="23" value={settings.send_hour} onChange={(event) => updateSettingsDraft({ send_hour: Number(event.target.value) })} className="mt-1 w-full" /><span className="block text-xs font-normal text-muted-foreground">Hora local, entre 0 y 23.</span></label>
                  <label htmlFor="invoice-timezone" className="block space-y-1.5 text-sm font-medium sm:col-span-2 lg:col-span-1">Zona horaria<Input id="invoice-timezone" required value={settings.timezone} onChange={(event) => updateSettingsDraft({ timezone: event.target.value })} className="mt-1 w-full" placeholder="America/Argentina/Buenos_Aires" /><span className="block text-xs font-normal text-muted-foreground">Ejemplo: America/Argentina/Buenos_Aires.</span></label>
                </div>
              </section>

              <section aria-labelledby="invoice-payment-heading" className="space-y-4 border-t pt-6">
                <div className="flex items-center gap-3">
                  <span className="grid size-9 place-items-center rounded-lg bg-muted text-muted-foreground"><FileCheck2 className="size-4" /></span>
                  <div><h3 id="invoice-payment-heading" className="text-sm font-semibold">Instrucciones de pago</h3><p className="text-xs text-muted-foreground">Agregá los datos que el cliente necesita para pagar.</p></div>
                </div>
                <label htmlFor="invoice-payment-instructions" className="sr-only">Instrucciones de pago</label>
                <Textarea id="invoice-payment-instructions" className="min-h-28 w-full resize-none" rows={4} value={settings.payment_instructions ?? ""} onChange={(event) => updateSettingsDraft({ payment_instructions: event.target.value })} placeholder="Alias, CBU u otros pasos para completar el pago…" />
              </section>

              <div className="flex flex-col gap-4 rounded-xl border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div className="flex items-start gap-3">
                  <span className={`mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg ${settings.enabled ? "bg-primary/10 text-primary" : "bg-muted text-muted-foreground"}`}><MessageCircle className="size-4" /></span>
                  <div><div className="flex flex-wrap items-center gap-2"><p className="text-sm font-semibold">Envíos automáticos</p><Badge variant={settings.enabled ? "secondary" : "outline"}>{settings.enabled ? "Activos" : "Pausados"}</Badge></div><p className="mt-1 max-w-xl text-xs leading-relaxed text-muted-foreground">Al activar, los cobros emitidos se enviarán por WhatsApp según estas plantillas y horarios.</p></div>
                </div>
                <div className="flex items-center gap-3 self-end sm:self-center"><span className="text-xs text-muted-foreground">{settings.enabled ? "Activado" : "Desactivado"}</span><Switch checked={settings.enabled} onCheckedChange={(enabled) => updateSettingsDraft({ enabled })} aria-label="Activar Invoices y sus envíos automáticos" /></div>
              </div>

              <div className="flex flex-col-reverse gap-2 border-t pt-5 sm:flex-row sm:justify-end">
                <Button type="button" variant="ghost" onClick={() => { setSettingsFormError(""); setShowSettings(false) }} className="sm:min-w-28">Cancelar</Button>
                <Button disabled={busy} className="sm:min-w-48">{busy ? "Guardando…" : "Guardar configuración"}</Button>
              </div>
            </form>
          </CardContent>
        </Card>}

        <div className="grid gap-3 sm:grid-cols-3"><Card><CardContent className="flex items-center gap-4 p-5"><span className="grid size-10 place-items-center rounded-xl bg-amber-500/10 text-amber-600"><Wallet className="size-5" /></span><div><p className="text-xs text-muted-foreground">Saldo pendiente</p><p className="mt-1 text-xl font-semibold">{money(invoiceSummary.outstanding_cents)}</p></div></CardContent></Card><Card><CardContent className="flex items-center gap-4 p-5"><span className="grid size-10 place-items-center rounded-xl bg-red-500/10 text-red-600"><Clock3 className="size-5" /></span><div><p className="text-xs text-muted-foreground">Saldo vencido</p><p className="mt-1 text-xl font-semibold">{money(invoiceSummary.overdue_balance_cents)}</p><p className="mt-1 text-xs text-muted-foreground">{invoiceSummary.overdue_count} cobros</p></div></CardContent></Card><Card><CardContent className="flex items-center gap-4 p-5"><span className="grid size-10 place-items-center rounded-xl bg-primary/10 text-primary"><Repeat2 className="size-5" /></span><div><p className="text-xs text-muted-foreground">Recurrencias activas</p><p className="mt-1 text-xl font-semibold">{recurrences.filter((item) => item.status === "active").length}</p></div></CardContent></Card></div>

        <Tabs defaultValue="invoices" className="space-y-4"><div className="flex flex-wrap items-center justify-between gap-3"><TabsList><TabsTrigger value="invoices">Cobros</TabsTrigger><TabsTrigger value="recurrences">Recurrentes</TabsTrigger></TabsList><Button variant="ghost" size="sm" onClick={() => void reload()} aria-label="Actualizar lista"><RefreshCw className="mr-2 size-4" /> Actualizar</Button></div>
          <TabsContent value="invoices" className="space-y-4"><div className="flex flex-col gap-3 sm:flex-row"><div className="relative w-full max-w-sm"><Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" /><Input className="pl-9" placeholder="Buscar cliente o cobro" value={search} onChange={(event) => setSearch(event.target.value)} /></div><Select value={collectionStatusFilter} onValueChange={(value) => { setCollectionStatusFilter(value as InvoiceCollectionStatus | "all"); setInvoicePage(1) }}><SelectTrigger aria-label="Filtrar cobros por estado" className="w-full sm:w-56"><SelectValue placeholder="Todos los estados" /></SelectTrigger><SelectContent><SelectItem value="all">Todos los estados</SelectItem><SelectItem value="pending">Pendiente</SelectItem><SelectItem value="overdue">Impago</SelectItem><SelectItem value="partial">Pago parcial</SelectItem><SelectItem value="partial_overdue">Pago parcial · vencido</SelectItem><SelectItem value="paid">Pagado</SelectItem></SelectContent></Select></div>
            <Card className="overflow-hidden"><div className="overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="bg-muted/45 text-left text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-5 py-3">Invoice</th><th className="px-5 py-3">Cliente</th><th className="px-5 py-3">Vencimiento</th><th className="px-5 py-3 text-right">Total</th><th className="px-5 py-3 text-right">Saldo</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3" /></tr></thead><tbody className="divide-y">{loading ? <tr><td className="px-5 py-12 text-center text-muted-foreground" colSpan={7}>Cargando cobros…</td></tr> : visibleInvoices.length === 0 ? <tr><td className="px-5 py-14 text-center text-muted-foreground" colSpan={7}>{collectionStatusFilter !== "all" || invoiceSearch ? "No hay cobros que coincidan con la búsqueda o el filtro." : "Todavía no hay cobros. Creá uno para empezar."}</td></tr> : visibleInvoices.map((invoice) => <tr key={invoice.id} className="hover:bg-muted/25"><td className="px-5 py-4"><button className="font-medium text-primary underline-offset-4 hover:underline" onClick={() => void openInvoice(invoice.id)}>{invoice.number}</button><p className="max-w-56 truncate text-xs text-muted-foreground">{invoice.concept}</p></td><td className="px-5 py-4">{invoice.contact?.name ?? "Contacto"}</td><td className="px-5 py-4">{date(invoice.due_on)}</td><td className="px-5 py-4 text-right font-medium">{money(invoice.amount_cents)}</td><td className="px-5 py-4 text-right">{money(invoice.balance_cents ?? invoice.amount_cents - (invoice.paid_cents ?? 0))}</td><td className="px-5 py-4"><div className="flex flex-col items-start gap-1"><Badge variant={invoice.status === "void" ? "outline" : collectionStatusVariant(invoice.collection_status)}>{invoice.status === "void" ? "Anulado" : collectionStatusLabel(invoice.collection_status)}</Badge>{invoice.status === "issued" ? <span className={`text-xs ${invoice.delivery_status === "failed" ? "text-destructive" : "text-muted-foreground"}`}>{deliveryStatusLabel(invoice.delivery_status)}</span> : invoice.status !== "void" && <span className="text-xs text-muted-foreground">{invoice.status === "scheduled" ? "Programado" : "Borrador"}</span>}</div></td><td className="px-5 py-4 text-right">{invoice.status === "issued" && <Button variant="ghost" size="icon" aria-label={`Descargar ${invoice.number}`} onClick={() => void downloadPdf(invoice.id)}><ArrowDownToLine className="size-4" /></Button>}</td></tr>)}</tbody></table></div></Card>
            <div className="flex items-center justify-between"><p className="text-xs text-muted-foreground">Página {invoicePage} de {invoicePages}</p><div className="flex gap-2"><Button variant="outline" size="sm" disabled={invoicePage <= 1 || loading} onClick={() => setInvoicePage((page) => Math.max(1, page - 1))}>Anterior</Button><Button variant="outline" size="sm" disabled={invoicePage >= invoicePages || loading} onClick={() => setInvoicePage((page) => Math.min(invoicePages, page + 1))}>Siguiente</Button></div></div>
          </TabsContent>
          <TabsContent value="recurrences"><Card className="overflow-hidden"><div className="overflow-x-auto"><table className="w-full min-w-[700px] text-sm"><thead className="bg-muted/45 text-left text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-5 py-3">Concepto</th><th className="px-5 py-3">Cliente</th><th className="px-5 py-3">Frecuencia</th><th className="px-5 py-3">Próximo cobro</th><th className="px-5 py-3 text-right">Importe</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3" /></tr></thead><tbody className="divide-y">{loading ? <tr><td className="px-5 py-12 text-center text-muted-foreground" colSpan={7}>Cargando recurrencias…</td></tr> : recurrences.length === 0 ? <tr><td className="px-5 py-14 text-center" colSpan={7}><Repeat2 className="mx-auto mb-3 size-8 text-muted-foreground/50"/><p className="font-medium">Sin cobros recurrentes</p><p className="mt-1 text-muted-foreground">Las recurrencias generan un invoice separado por cada período.</p></td></tr> : recurrences.map((recurrence) => <tr key={recurrence.id} className="hover:bg-muted/25"><td className="px-5 py-4 font-medium">{recurrence.concept}</td><td className="px-5 py-4">{recurrence.contact?.name}</td><td className="px-5 py-4">Cada {recurrence.interval_count} {recurrence.interval_unit}</td><td className="px-5 py-4">{date(recurrence.next_occurrence_on)}</td><td className="px-5 py-4 text-right font-medium">{money(recurrence.amount_cents)}</td><td className="px-5 py-4"><Badge variant={recurrence.status === "active" ? "default" : "outline"}>{recurrence.status === "active" ? "Activa" : recurrence.status === "draft" ? "Borrador" : recurrence.status === "paused" ? "Pausada" : recurrence.status === "completed" ? "Completada" : "Cancelada"}</Badge></td><td className="px-5 py-4 text-right">{canManage && ["draft", "active"].includes(recurrence.status) && <Button size="sm" variant="ghost" onClick={() => { setRecurrenceToEdit(recurrence); setRecurrenceEdit({ concept: recurrence.concept, amount: (recurrence.amount_cents / 100).toFixed(2), interval_count: String(recurrence.interval_count), interval_unit: recurrence.interval_unit, payment_term_days: String(recurrence.payment_term_days), ends_on: "" }) }}>Editar</Button>}{canManage && recurrence.status === "draft" && <Button size="sm" variant="outline" disabled={busy || !settings?.enabled} onClick={() => void activateRecurrence(recurrence.id)}><Check className="mr-1 size-4" /> Activar</Button>}{canManage && recurrence.status === "active" && <Button size="sm" variant="ghost" onClick={() => void changeRecurrenceState(recurrence.id, "pause")}><ChevronDown className="mr-1 size-4" /> Pausar</Button>}{canManage && recurrence.status === "paused" && <Button size="sm" variant="outline" onClick={() => void changeRecurrenceState(recurrence.id, "resume")}>Reanudar</Button>}{canManage && ["draft", "active", "paused"].includes(recurrence.status) && <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setRecurrenceToCancel(recurrence.id)}>Cancelar</Button>}</td></tr>)}</tbody></table></div></Card></TabsContent>
        </Tabs>

        <Dialog open={recurrenceToEdit !== null} onOpenChange={(open) => !open && setRecurrenceToEdit(null)}><DialogContent><DialogHeader><DialogTitle>Editar recurrencia</DialogTitle><p className="text-sm text-muted-foreground">Los invoices ya generados mantienen su importe y concepto originales.</p></DialogHeader><form onSubmit={saveRecurrenceEdit} className="space-y-3"><label className="block space-y-1 text-sm">Concepto<Input required maxLength={500} value={recurrenceEdit.concept} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, concept: event.target.value })} /></label><label className="block space-y-1 text-sm">Importe en ARS<Input required type="number" min="0.01" step="0.01" value={recurrenceEdit.amount} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, amount: event.target.value })} /></label><div className="grid grid-cols-2 gap-3"><label className="space-y-1 text-sm">Cada<Input type="number" min="1" max="365" value={recurrenceEdit.interval_count} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, interval_count: event.target.value })} /></label><label className="space-y-1 text-sm">Período<select className="h-10 w-full rounded-md border bg-background px-3" value={recurrenceEdit.interval_unit} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, interval_unit: event.target.value })}><option value="days">Días</option><option value="weeks">Semanas</option><option value="months">Meses</option><option value="years">Años</option></select></label></div><label className="block space-y-1 text-sm">Días para pagar<Input type="number" min="0" max="365" value={recurrenceEdit.payment_term_days} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, payment_term_days: event.target.value })} /></label><label className="block space-y-1 text-sm">Fecha final (vacío = sin límite)<Input type="date" value={recurrenceEdit.ends_on} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, ends_on: event.target.value })} /></label><div className="flex justify-end gap-2 pt-2"><Button type="button" variant="ghost" onClick={() => setRecurrenceToEdit(null)}>Cancelar</Button><Button disabled={busy}>Guardar cambios</Button></div></form></DialogContent></Dialog>

        <Dialog open={recurrenceToCancel !== null} onOpenChange={(open) => !open && setRecurrenceToCancel(null)}><DialogContent><DialogHeader><DialogTitle>Cancelar recurrencia</DialogTitle><p className="text-sm text-muted-foreground">Los cobros ya generados conservarán su historial y saldo. No se crearán nuevos períodos.</p></DialogHeader><div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setRecurrenceToCancel(null)}>Seguir activa</Button><Button variant="destructive" disabled={busy || recurrenceToCancel === null} onClick={() => recurrenceToCancel !== null && void changeRecurrenceState(recurrenceToCancel, "cancel")}>Cancelar recurrencia</Button></div></DialogContent></Dialog>

        {selectedInvoice && <Dialog open={Boolean(selectedInvoice)} onOpenChange={(open) => { if (!open) { setSelectedInvoice(null); setEditingInvoice(false); setPaymentToReverse(null) } }}><DialogContent className="max-h-[90vh] w-full max-w-2xl overflow-y-auto"><DialogHeader><DialogTitle id="invoice-detail-title">{selectedInvoice.number} · {selectedInvoice.concept}</DialogTitle><p className="text-sm text-muted-foreground">{selectedInvoice.contact?.name} · vence {date(selectedInvoice.due_on)}</p></DialogHeader>
          {selectedInvoice.status !== "void" && <div className="flex flex-wrap items-center gap-2"><Badge variant={collectionStatusVariant(selectedInvoice.collection_status)}>{collectionStatusLabel(selectedInvoice.collection_status)}</Badge>{selectedInvoice.status === "issued" && <span className={`text-xs ${selectedInvoice.delivery_status === "failed" ? "text-destructive" : "text-muted-foreground"}`}>{deliveryStatusLabel(selectedInvoice.delivery_status)}</span>}{selectedInvoice.status !== "issued" && <span className="text-xs text-muted-foreground">{selectedInvoice.status === "scheduled" ? "Programado" : "Borrador"}</span>}</div>}
          <div className="mt-5 grid grid-cols-3 gap-3"><div className="rounded-xl bg-muted/50 p-3"><p className="text-xs text-muted-foreground">Importe</p><p className="mt-1 font-semibold">{money(selectedInvoice.amount_cents)}</p></div><div className="rounded-xl bg-muted/50 p-3"><p className="text-xs text-muted-foreground">Pagado</p><p className="mt-1 font-semibold">{money(selectedInvoice.paid_cents ?? 0)}</p></div><div className="rounded-xl bg-muted/50 p-3"><p className="text-xs text-muted-foreground">Saldo</p><p className="mt-1 font-semibold">{money(selectedInvoice.balance_cents ?? selectedInvoice.amount_cents)}</p></div></div>
          <div className="mt-5 flex flex-wrap gap-2">{canManage && ["draft", "scheduled"].includes(selectedInvoice.status) && <Button variant="outline" onClick={() => setEditingInvoice((editing) => !editing)}>{editingInvoice ? "Cerrar edición" : "Editar"}</Button>}{canManage && selectedInvoice.status === "draft" && <Button disabled={busy || !settings?.enabled} onClick={() => void runInvoiceAction("issue")}>Emitir y enviar</Button>}{canManage && selectedInvoice.delivery_status === "failed" && <Button disabled={busy} onClick={() => void runInvoiceAction("resend")}>Reintentar envío</Button>}{selectedInvoice.status === "issued" && <Button variant="outline" onClick={() => void downloadPdf(selectedInvoice.id)}><ArrowDownToLine className="mr-2 size-4"/>Descargar PDF</Button>}</div>
          {editingInvoice && canManage && <form onSubmit={saveInvoiceEdits} className="mt-4 grid gap-3 rounded-xl border p-4 sm:grid-cols-2"><label className="space-y-1 text-xs sm:col-span-2">Concepto<Input required maxLength={500} value={editForm.concept} onChange={(event) => setEditForm({ ...editForm, concept: event.target.value })} /></label><label className="space-y-1 text-xs">Importe (ARS)<Input required type="number" min="0.01" step="0.01" value={editForm.amount} onChange={(event) => setEditForm({ ...editForm, amount: event.target.value })} /></label>{selectedInvoice.status === "scheduled" && <label className="space-y-1 text-xs">Fecha y hora de emisión<Input required type="datetime-local" value={editForm.scheduled_at} onChange={(event) => setEditForm({ ...editForm, scheduled_at: event.target.value })} /></label>}<div className="flex justify-end sm:col-span-2"><Button disabled={busy}>{busy ? "Guardando…" : "Guardar cambios"}</Button></div></form>}
          {canPay && selectedInvoice.status !== "void" && (selectedInvoice.balance_cents ?? 0) > 0 && <form onSubmit={submitPayment} className="mt-6 space-y-3 rounded-xl border p-4"><h3 className="font-medium">Registrar un pago</h3><p className="text-xs text-muted-foreground">El registro del pago es independiente del envío del ticket.</p><div className="grid gap-3 sm:grid-cols-3"><label className="space-y-1 text-xs">Importe (ARS)<Input required type="number" min="0.01" step="0.01" max={(selectedInvoice.balance_cents ?? 0) / 100} value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} /></label><label className="space-y-1 text-xs">Fecha<Input required type="date" value={paymentDate} onChange={(event) => setPaymentDate(event.target.value)} /></label><label className="space-y-1 text-xs">Medio<Input value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)} placeholder="Transferencia" /></label></div><div className="flex justify-end"><Button disabled={busy}>Guardar pago</Button></div></form>}
          <div className="mt-6"><h3 className="font-medium">Historial de pagos</h3><div className="mt-2 divide-y rounded-xl border">{(selectedInvoice.payments ?? []).length === 0 ? <p className="p-4 text-sm text-muted-foreground">Todavía no se registraron pagos.</p> : selectedInvoice.payments?.map((payment) => <div key={payment.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm"><div><p className="font-medium">{money(payment.amount_cents)} · {date(payment.paid_on)}</p><p className="text-xs text-muted-foreground">{payment.method || "Medio no indicado"}{payment.reversed_at ? ` · Revertido: ${payment.reversal_reason}` : ""}</p></div>{canPay && !payment.reversed_at && <Button size="sm" variant="ghost" onClick={() => { setPaymentToReverse(payment.id); setCorrectionReason("") }}>Revertir</Button>}</div>)}</div></div>
          {canPay && paymentToReverse !== null && <div className="mt-3 flex flex-col gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3"><p className="text-sm">Vas a revertir el pago de {money(selectedInvoice.payments?.find((payment) => payment.id === paymentToReverse)?.amount_cents ?? 0)}.</p><div className="flex gap-2"><Input aria-label="Motivo de reversión" value={correctionReason} onChange={(event) => setCorrectionReason(event.target.value)} placeholder="Motivo de reversión" /><Button variant="outline" disabled={busy || correctionReason.trim().length < 3} onClick={() => void reversePayment(paymentToReverse)}>Confirmar reversión</Button><Button variant="ghost" onClick={() => setPaymentToReverse(null)}>Cancelar</Button></div></div>}
          {canManage && selectedInvoice.status === "issued" && <div className="mt-4 flex gap-2">{(selectedInvoice.payments ?? []).some((payment) => !payment.reversed_at) ? <p className="text-xs text-muted-foreground">Para anular este cobro, primero revertí los pagos registrados.</p> : <><Input aria-label="Motivo de anulación" value={correctionReason} onChange={(event) => setCorrectionReason(event.target.value)} placeholder="Motivo para anular el invoice" /><Button variant="destructive" disabled={busy || correctionReason.trim().length < 3} onClick={() => void runInvoiceAction("void")}>Anular cobro</Button></>}</div>}
          <div className="mt-6"><h3 className="font-medium">Actividad y envíos</h3><div className="mt-2 space-y-2">{(selectedInvoice.events ?? []).map((event) => <div key={event.id} className="flex justify-between gap-3 rounded-lg bg-muted/35 px-3 py-2 text-xs"><span>{event.type.replaceAll("_", " ")}</span><span className="text-muted-foreground">{new Date(event.created_at).toLocaleString("es-AR")}</span></div>)}</div></div>
        </DialogContent></Dialog>}

        {showCreate && <Dialog open={showCreate} onOpenChange={setShowCreate}><DialogContent className="max-h-[90vh] w-full max-w-xl overflow-y-auto"><DialogHeader><DialogTitle id="invoice-create-title">Nuevo cobro</DialogTitle><p className="text-sm text-muted-foreground">Enviá un cobro ahora o programá su emisión.</p></DialogHeader><form onSubmit={submitCreate} className="space-y-4">
          <fieldset className="grid grid-cols-2 gap-2"><legend className="mb-2 text-sm font-medium">Tipo de cobro</legend><Button type="button" variant={kind === "once" ? "default" : "outline"} onClick={() => setKind("once")}>Único</Button><Button type="button" variant={kind === "repeat" ? "default" : "outline"} onClick={() => setKind("repeat")}>Recurrente</Button></fieldset>
          <label className="block space-y-1.5 text-sm">Cliente<select required className="h-10 w-full rounded-md border bg-background px-3" value={form.contact_id} onChange={(event) => setForm({ ...form, contact_id: event.target.value })}><option value="">Seleccionar contacto</option>{contacts.map((contact) => <option key={contact.id} value={contact.id}>{contact.name}{contact.phone ? ` · ${contact.phone}` : ""}</option>)}</select></label>
          <label className="block space-y-1.5 text-sm">Concepto<Input required maxLength={500} value={form.concept} onChange={(event) => setForm({ ...form, concept: event.target.value })} placeholder="Ej. Abono mensual de soporte" /></label>
          <label className="block space-y-1.5 text-sm">Importe en ARS<Input required type="number" min="0.01" step="0.01" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} placeholder="50000,00" /></label>
          {kind === "once" ? <><label className="block space-y-1.5 text-sm">Emisión<select className="h-10 w-full rounded-md border bg-background px-3" value={form.send} onChange={(event) => setForm({ ...form, send: event.target.value })}><option value="draft">Guardar como borrador</option><option value="now">Enviar ahora</option><option value="schedule">Programar envío</option></select></label>{form.send === "schedule" && <label className="block space-y-1.5 text-sm">Fecha y hora<Input required type="datetime-local" value={form.scheduled_at} onChange={(event) => setForm({ ...form, scheduled_at: event.target.value })} /></label>}</> : <><div className="grid grid-cols-2 gap-3"><label className="space-y-1.5 text-sm">Cada<Input type="number" min="1" max="365" value={form.interval_count} onChange={(event) => setForm({ ...form, interval_count: event.target.value })} /></label><label className="space-y-1.5 text-sm">Período<select className="h-10 w-full rounded-md border bg-background px-3" value={form.interval_unit} onChange={(event) => setForm({ ...form, interval_unit: event.target.value })}><option value="days">Días</option><option value="weeks">Semanas</option><option value="months">Meses</option><option value="years">Años</option></select></label></div><div className="grid grid-cols-2 gap-3"><label className="space-y-1.5 text-sm">Primera emisión<Input required type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} /></label><label className="space-y-1.5 text-sm">Última emisión (opcional)<Input type="date" value={form.ends_on} onChange={(event) => setForm({ ...form, ends_on: event.target.value })} /></label></div><label className="block space-y-1.5 text-sm">Días para pagar<Input type="number" min="0" max="365" value={form.payment_term_days} onChange={(event) => setForm({ ...form, payment_term_days: event.target.value })} /></label><p className="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">La recurrencia queda en borrador. Revisá su calendario y activala desde la pestaña Recurrentes.</p></>}
          {kind === "once" && settings?.enabled === false && form.send !== "draft" && <p className="rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800">Activá Invoices y configurá las plantillas para poder emitir y enviar.</p>}
          <div className="flex justify-end gap-2 border-t pt-4"><Button type="button" variant="ghost" onClick={() => setShowCreate(false)}>Cancelar</Button><Button disabled={busy || (kind === "once" && form.send !== "draft" && !settings?.enabled)}>{busy ? "Guardando…" : kind === "repeat" ? "Guardar borrador" : form.send === "now" ? "Emitir y enviar" : form.send === "schedule" ? "Programar envío" : "Guardar borrador"}</Button></div>
        </form></DialogContent></Dialog>}
      </div>
    </main>
  </SidebarLayout>
}
