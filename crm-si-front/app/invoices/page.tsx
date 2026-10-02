"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { ArrowDownToLine, Check, ChevronDown, CircleAlert, Clock3, FileText, Plus, RefreshCw, Repeat2, Search, Settings2, Wallet } from "lucide-react"
import { SidebarLayout } from "@/components/SidebarLayout"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Badge } from "@/components/ui/badge"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { useToast } from "@/components/Toast"
import { getContacts, type Contact } from "@/lib/api/contacts"
import { getChannels } from "@/lib/api/channels"
import { ChannelType } from "@/data/enums"
import { useAuthStore } from "@/store/useAuthStore"
import { getAuthToken, workspaceHeaders } from "@/lib/api/auth-token"
import { createInvoice, createRecurrence, getInvoice, getInvoiceSettings, issueInvoice, listInvoiceRecurrences, listInvoices, recordInvoicePayment, recurrenceAction, resendInvoice, reverseInvoicePayment, saveInvoiceSettings, updateInvoice, updateRecurrence, voidInvoice, type InvoiceRecord, type InvoiceRecurrenceRecord, type InvoiceSettingsRecord } from "@/lib/api/invoices"

const money = (cents: number) => new Intl.NumberFormat("es-AR", { style: "currency", currency: "ARS" }).format(cents / 100)
const date = (value?: string | null) => value ? new Intl.DateTimeFormat("es-AR", { dateStyle: "medium" }).format(new Date(`${value.slice(0, 10)}T12:00:00`)) : "—"

export default function InvoicesPage() {
  const { addToast } = useToast()
  const { permissions, role } = useAuthStore()
  const isOwner = role?.is_owner === true
  const canManage = isOwner || permissions.includes("invoices.manage")
  const canConfigure = isOwner || permissions.includes("invoices.configure")
  const canPay = isOwner || permissions.includes("invoices.payments")
  const [invoices, setInvoices] = useState<InvoiceRecord[]>([])
  const [recurrences, setRecurrences] = useState<InvoiceRecurrenceRecord[]>([])
  const [contacts, setContacts] = useState<Contact[]>([])
  const [settings, setSettings] = useState<InvoiceSettingsRecord | null>(null)
  const [templates, setTemplates] = useState<Array<{ id: number; name: string; header_format: string | null; parameters: string[] }>>([])
  const [channels, setChannels] = useState<Array<{ id: number; name: string; type: number; status: string }>>([])
  const [loading, setLoading] = useState(true)
  const [invoicePage, setInvoicePage] = useState(1)
  const [invoicePages, setInvoicePages] = useState(1)
  const [busy, setBusy] = useState(false)
  const [showCreate, setShowCreate] = useState(false)
  const [showSettings, setShowSettings] = useState(false)
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
  const [form, setForm] = useState({ contact_id: "", concept: "", amount: "", send: "draft", scheduled_at: "", interval_unit: "months", interval_count: "1", starts_on: new Date().toISOString().slice(0, 10), ends_on: "", payment_term_days: "10" })

  const reload = useCallback(async () => {
    setLoading(true)
    try {
      const [invoiceResult, recurrenceRows, contactRows, config, channelRows] = await Promise.all([listInvoices(invoicePage), listInvoiceRecurrences(), getContacts({ per_page: 100 }), getInvoiceSettings(), getChannels()])
      setInvoices(invoiceResult.rows); setInvoicePages(invoiceResult.pages); setRecurrences(recurrenceRows); setContacts(contactRows)
      setSettings(config.settings); setTemplates(config.templates)
      setChannels(channelRows.filter((channel) => channel.type === ChannelType.WHATSAPP) as typeof channelRows)
    } catch (error) {
      addToast({ type: "error", title: "No se pudo cargar Invoices", description: error instanceof Error ? error.message : "Intentá de nuevo." })
    } finally { setLoading(false) }
  }, [addToast, invoicePage])

  useEffect(() => { void reload() }, [reload])

  const visibleInvoices = useMemo(() => invoices.filter((invoice) => `${invoice.number} ${invoice.concept} ${invoice.contact?.name ?? ""}`.toLowerCase().includes(search.toLowerCase())), [invoices, search])
  const issued = invoices.filter((invoice) => invoice.status === "issued")
  const balance = issued.reduce((sum, invoice) => sum + Math.max(0, invoice.amount_cents - (invoice.paid_cents ?? 0)), 0)
  const overdue = issued.filter((invoice) => invoice.due_on && invoice.due_on < new Date().toISOString().slice(0, 10) && (invoice.paid_cents ?? 0) < invoice.amount_cents).length

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

  async function updateSettings(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!settings) return; setBusy(true)
    try { await saveInvoiceSettings(settings); addToast({ type: "success", title: "Configuración guardada" }); setShowSettings(false); await reload() }
    catch (error) { addToast({ type: "error", title: "No se pudo guardar la configuración", description: error instanceof Error ? error.message : "Revisá las plantillas y el canal." }) }
    finally { setBusy(false) }
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
            {canConfigure && <Button variant="outline" onClick={() => setShowSettings((shown) => !shown)}><Settings2 className="mr-2 size-4" /> Configurar</Button>}
            {canManage && <Button onClick={() => setShowCreate(true)}><Plus className="mr-2 size-4" /> Nuevo cobro</Button>}
          </div>
        </header>

        {settings && !settings.enabled && <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"><CircleAlert className="mt-0.5 size-5 shrink-0 text-amber-600" /><div><p className="font-medium">Configurá WhatsApp para habilitar los envíos automáticos</p><p className="mt-1 text-muted-foreground">Podés guardar borradores ahora; la emisión queda deshabilitada hasta elegir plantillas aprobadas.</p></div></div>}

        {canConfigure && showSettings && settings && <Card className="border-primary/20 shadow-sm"><CardHeader><CardTitle>Configuración de cobros</CardTitle><p className="text-sm text-muted-foreground">Estos datos aparecen en los PDF y rigen las fechas de emisión.</p></CardHeader><CardContent><form onSubmit={updateSettings} className="grid gap-4 md:grid-cols-2">
          <label className="space-y-1.5 text-sm">Nombre del negocio<Input required value={settings.business_name ?? ""} onChange={(event) => setSettings({ ...settings, business_name: event.target.value })} /></label>
          <label className="space-y-1.5 text-sm">Canal de WhatsApp<select required className="h-10 rounded-md border bg-background px-3" value={settings.whatsapp_channel_id ?? ""} onChange={async (event) => { const channelId = event.target.value ? Number(event.target.value) : null; setSettings({ ...settings, whatsapp_channel_id: channelId, whatsapp_template_id: null, reminder_template_id: null }); if (channelId) { const token = getAuthToken(); const response = await fetch(`/api/channels/${channelId}/templates?status=all`, { headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() } }); const data = await response.json().catch(() => []); setTemplates((Array.isArray(data) ? data : data.data ?? []).filter((item: { status: string }) => item.status === "approved").map((item: { id: number; name: string; components?: Array<{ type: string; format?: string }>; expected_body_parameters?: string[] }) => ({ id: item.id, name: item.name, header_format: item.components?.find((part) => part.type.toUpperCase() === "HEADER")?.format ?? null, parameters: item.expected_body_parameters ?? [] }))) } }}><option value="">Seleccionar canal</option>{channels.map((channel) => <option key={channel.id} value={channel.id}>{channel.name}</option>)}</select></label>
          <label className="space-y-1.5 text-sm">Plantilla de invoice<select required className="h-10 rounded-md border bg-background px-3" value={settings.whatsapp_template_id ?? ""} onChange={(event) => setSettings({ ...settings, whatsapp_template_id: event.target.value ? Number(event.target.value) : null })}><option value="">Elegí plantilla aprobada con documento</option>{templatesForChannel.filter((template) => template.header_format === "DOCUMENT").map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}</select></label>
          <label className="space-y-1.5 text-sm">Plantilla de recordatorio<select required className="h-10 rounded-md border bg-background px-3" value={settings.reminder_template_id ?? ""} onChange={(event) => setSettings({ ...settings, reminder_template_id: event.target.value ? Number(event.target.value) : null })}><option value="">Elegí plantilla aprobada sin archivo</option>{templatesForChannel.filter((template) => !template.header_format).map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}</select></label>
          <label className="space-y-1.5 text-sm">Días para pagar<Input type="number" min="0" max="365" value={settings.payment_term_days} onChange={(event) => setSettings({ ...settings, payment_term_days: Number(event.target.value) })} /></label>
          <label className="space-y-1.5 text-sm">Hora de envíos (hora local)<Input type="number" min="0" max="23" value={settings.send_hour} onChange={(event) => setSettings({ ...settings, send_hour: Number(event.target.value) })} /></label>
          <label className="space-y-1.5 text-sm">Zona horaria<Input required value={settings.timezone} onChange={(event) => setSettings({ ...settings, timezone: event.target.value })} /></label>
          <label className="space-y-1.5 text-sm md:col-span-2">Instrucciones de pago<Textarea className="resize-none" rows={3} value={settings.payment_instructions ?? ""} onChange={(event) => setSettings({ ...settings, payment_instructions: event.target.value })} /></label>
          <label className="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" checked={settings.enabled} onChange={(event) => setSettings({ ...settings, enabled: event.target.checked })} /> Activar Invoices y sus envíos automáticos</label>
          <div className="flex justify-end gap-2 md:col-span-2"><Button type="button" variant="ghost" onClick={() => setShowSettings(false)}>Cancelar</Button><Button disabled={busy}>{busy ? "Guardando…" : "Guardar configuración"}</Button></div>
        </form></CardContent></Card>}

        <div className="grid gap-3 sm:grid-cols-3"><Card><CardContent className="flex items-center gap-4 p-5"><span className="grid size-10 place-items-center rounded-xl bg-amber-500/10 text-amber-600"><Wallet className="size-5" /></span><div><p className="text-xs text-muted-foreground">Saldo pendiente</p><p className="mt-1 text-xl font-semibold">{money(balance)}</p></div></CardContent></Card><Card><CardContent className="flex items-center gap-4 p-5"><span className="grid size-10 place-items-center rounded-xl bg-red-500/10 text-red-600"><Clock3 className="size-5" /></span><div><p className="text-xs text-muted-foreground">Cobros vencidos</p><p className="mt-1 text-xl font-semibold">{overdue}</p></div></CardContent></Card><Card><CardContent className="flex items-center gap-4 p-5"><span className="grid size-10 place-items-center rounded-xl bg-primary/10 text-primary"><Repeat2 className="size-5" /></span><div><p className="text-xs text-muted-foreground">Recurrencias activas</p><p className="mt-1 text-xl font-semibold">{recurrences.filter((item) => item.status === "active").length}</p></div></CardContent></Card></div>

        <Tabs defaultValue="invoices" className="space-y-4"><div className="flex flex-wrap items-center justify-between gap-3"><TabsList><TabsTrigger value="invoices">Cobros</TabsTrigger><TabsTrigger value="recurrences">Recurrentes</TabsTrigger></TabsList><Button variant="ghost" size="sm" onClick={() => void reload()} aria-label="Actualizar lista"><RefreshCw className="mr-2 size-4" /> Actualizar</Button></div>
          <TabsContent value="invoices" className="space-y-4"><div className="relative max-w-sm"><Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" /><Input className="pl-9" placeholder="Buscar cliente o cobro" value={search} onChange={(event) => setSearch(event.target.value)} /></div>
            <Card className="overflow-hidden"><div className="overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="bg-muted/45 text-left text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-5 py-3">Invoice</th><th className="px-5 py-3">Cliente</th><th className="px-5 py-3">Vencimiento</th><th className="px-5 py-3 text-right">Total</th><th className="px-5 py-3 text-right">Saldo</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3" /></tr></thead><tbody className="divide-y">{loading ? <tr><td className="px-5 py-12 text-center text-muted-foreground" colSpan={7}>Cargando cobros…</td></tr> : visibleInvoices.length === 0 ? <tr><td className="px-5 py-14 text-center" colSpan={7}><FileText className="mx-auto mb-3 size-8 text-muted-foreground/50"/><p className="font-medium">Todavía no hay cobros</p><p className="mt-1 text-muted-foreground">Creá un cobro único o una recurrencia para empezar.</p></td></tr> : visibleInvoices.map((invoice) => <tr key={invoice.id} className="hover:bg-muted/25"><td className="px-5 py-4"><button className="font-medium text-primary underline-offset-4 hover:underline" onClick={() => void openInvoice(invoice.id)}>{invoice.number}</button><p className="max-w-56 truncate text-xs text-muted-foreground">{invoice.concept}</p></td><td className="px-5 py-4">{invoice.contact?.name ?? "Contacto"}</td><td className="px-5 py-4">{date(invoice.due_on)}</td><td className="px-5 py-4 text-right font-medium">{money(invoice.amount_cents)}</td><td className="px-5 py-4 text-right">{money(invoice.balance_cents ?? invoice.amount_cents - (invoice.paid_cents ?? 0))}</td><td className="px-5 py-4"><Badge variant={invoice.delivery_status === "failed" ? "destructive" : invoice.status === "issued" ? "secondary" : "outline"}>{invoice.status === "issued" ? (invoice.delivery_status === "failed" ? "Envío fallido" : invoice.payment_state === "paid" ? "Pagado" : invoice.payment_state === "partial" ? "Parcial" : "Pendiente") : invoice.status === "scheduled" ? "Programado" : invoice.status === "void" ? "Anulado" : "Borrador"}</Badge></td><td className="px-5 py-4 text-right">{invoice.status === "issued" && <Button variant="ghost" size="icon" aria-label={`Descargar ${invoice.number}`} onClick={() => void downloadPdf(invoice.id)}><ArrowDownToLine className="size-4" /></Button>}</td></tr>)}</tbody></table></div></Card>
            <div className="flex items-center justify-between"><p className="text-xs text-muted-foreground">Página {invoicePage} de {invoicePages}</p><div className="flex gap-2"><Button variant="outline" size="sm" disabled={invoicePage <= 1 || loading} onClick={() => setInvoicePage((page) => Math.max(1, page - 1))}>Anterior</Button><Button variant="outline" size="sm" disabled={invoicePage >= invoicePages || loading} onClick={() => setInvoicePage((page) => Math.min(invoicePages, page + 1))}>Siguiente</Button></div></div>
          </TabsContent>
          <TabsContent value="recurrences"><Card className="overflow-hidden"><div className="overflow-x-auto"><table className="w-full min-w-[700px] text-sm"><thead className="bg-muted/45 text-left text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-5 py-3">Concepto</th><th className="px-5 py-3">Cliente</th><th className="px-5 py-3">Frecuencia</th><th className="px-5 py-3">Próximo cobro</th><th className="px-5 py-3 text-right">Importe</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3" /></tr></thead><tbody className="divide-y">{loading ? <tr><td className="px-5 py-12 text-center text-muted-foreground" colSpan={7}>Cargando recurrencias…</td></tr> : recurrences.length === 0 ? <tr><td className="px-5 py-14 text-center" colSpan={7}><Repeat2 className="mx-auto mb-3 size-8 text-muted-foreground/50"/><p className="font-medium">Sin cobros recurrentes</p><p className="mt-1 text-muted-foreground">Las recurrencias generan un invoice separado por cada período.</p></td></tr> : recurrences.map((recurrence) => <tr key={recurrence.id} className="hover:bg-muted/25"><td className="px-5 py-4 font-medium">{recurrence.concept}</td><td className="px-5 py-4">{recurrence.contact?.name}</td><td className="px-5 py-4">Cada {recurrence.interval_count} {recurrence.interval_unit}</td><td className="px-5 py-4">{date(recurrence.next_occurrence_on)}</td><td className="px-5 py-4 text-right font-medium">{money(recurrence.amount_cents)}</td><td className="px-5 py-4"><Badge variant={recurrence.status === "active" ? "default" : "outline"}>{recurrence.status === "active" ? "Activa" : recurrence.status === "draft" ? "Borrador" : recurrence.status === "paused" ? "Pausada" : recurrence.status === "completed" ? "Completada" : "Cancelada"}</Badge></td><td className="px-5 py-4 text-right">{canManage && ["draft", "active"].includes(recurrence.status) && <Button size="sm" variant="ghost" onClick={() => { setRecurrenceToEdit(recurrence); setRecurrenceEdit({ concept: recurrence.concept, amount: (recurrence.amount_cents / 100).toFixed(2), interval_count: String(recurrence.interval_count), interval_unit: recurrence.interval_unit, payment_term_days: String(recurrence.payment_term_days), ends_on: "" }) }}>Editar</Button>}{canManage && recurrence.status === "draft" && <Button size="sm" variant="outline" disabled={busy || !settings?.enabled} onClick={() => void activateRecurrence(recurrence.id)}><Check className="mr-1 size-4" /> Activar</Button>}{canManage && recurrence.status === "active" && <Button size="sm" variant="ghost" onClick={() => void changeRecurrenceState(recurrence.id, "pause")}><ChevronDown className="mr-1 size-4" /> Pausar</Button>}{canManage && recurrence.status === "paused" && <Button size="sm" variant="outline" onClick={() => void changeRecurrenceState(recurrence.id, "resume")}>Reanudar</Button>}{canManage && ["draft", "active", "paused"].includes(recurrence.status) && <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setRecurrenceToCancel(recurrence.id)}>Cancelar</Button>}</td></tr>)}</tbody></table></div></Card></TabsContent>
        </Tabs>

        <Dialog open={recurrenceToEdit !== null} onOpenChange={(open) => !open && setRecurrenceToEdit(null)}><DialogContent><DialogHeader><DialogTitle>Editar recurrencia</DialogTitle><p className="text-sm text-muted-foreground">Los invoices ya generados mantienen su importe y concepto originales.</p></DialogHeader><form onSubmit={saveRecurrenceEdit} className="space-y-3"><label className="block space-y-1 text-sm">Concepto<Input required maxLength={500} value={recurrenceEdit.concept} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, concept: event.target.value })} /></label><label className="block space-y-1 text-sm">Importe en ARS<Input required type="number" min="0.01" step="0.01" value={recurrenceEdit.amount} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, amount: event.target.value })} /></label><div className="grid grid-cols-2 gap-3"><label className="space-y-1 text-sm">Cada<Input type="number" min="1" max="365" value={recurrenceEdit.interval_count} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, interval_count: event.target.value })} /></label><label className="space-y-1 text-sm">Período<select className="h-10 w-full rounded-md border bg-background px-3" value={recurrenceEdit.interval_unit} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, interval_unit: event.target.value })}><option value="days">Días</option><option value="weeks">Semanas</option><option value="months">Meses</option><option value="years">Años</option></select></label></div><label className="block space-y-1 text-sm">Días para pagar<Input type="number" min="0" max="365" value={recurrenceEdit.payment_term_days} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, payment_term_days: event.target.value })} /></label><label className="block space-y-1 text-sm">Fecha final (vacío = sin límite)<Input type="date" value={recurrenceEdit.ends_on} onChange={(event) => setRecurrenceEdit({ ...recurrenceEdit, ends_on: event.target.value })} /></label><div className="flex justify-end gap-2 pt-2"><Button type="button" variant="ghost" onClick={() => setRecurrenceToEdit(null)}>Cancelar</Button><Button disabled={busy}>Guardar cambios</Button></div></form></DialogContent></Dialog>

        <Dialog open={recurrenceToCancel !== null} onOpenChange={(open) => !open && setRecurrenceToCancel(null)}><DialogContent><DialogHeader><DialogTitle>Cancelar recurrencia</DialogTitle><p className="text-sm text-muted-foreground">Los cobros ya generados conservarán su historial y saldo. No se crearán nuevos períodos.</p></DialogHeader><div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setRecurrenceToCancel(null)}>Seguir activa</Button><Button variant="destructive" disabled={busy || recurrenceToCancel === null} onClick={() => recurrenceToCancel !== null && void changeRecurrenceState(recurrenceToCancel, "cancel")}>Cancelar recurrencia</Button></div></DialogContent></Dialog>

        {selectedInvoice && <Dialog open={Boolean(selectedInvoice)} onOpenChange={(open) => { if (!open) { setSelectedInvoice(null); setEditingInvoice(false); setPaymentToReverse(null) } }}><DialogContent className="max-h-[90vh] w-full max-w-2xl overflow-y-auto"><DialogHeader><DialogTitle id="invoice-detail-title">{selectedInvoice.number} · {selectedInvoice.concept}</DialogTitle><p className="text-sm text-muted-foreground">{selectedInvoice.contact?.name} · vence {date(selectedInvoice.due_on)}</p></DialogHeader>
          <div className="mt-5 grid grid-cols-3 gap-3"><div className="rounded-xl bg-muted/50 p-3"><p className="text-xs text-muted-foreground">Importe</p><p className="mt-1 font-semibold">{money(selectedInvoice.amount_cents)}</p></div><div className="rounded-xl bg-muted/50 p-3"><p className="text-xs text-muted-foreground">Pagado</p><p className="mt-1 font-semibold">{money(selectedInvoice.paid_cents ?? 0)}</p></div><div className="rounded-xl bg-muted/50 p-3"><p className="text-xs text-muted-foreground">Saldo</p><p className="mt-1 font-semibold">{money(selectedInvoice.balance_cents ?? selectedInvoice.amount_cents)}</p></div></div>
          <div className="mt-5 flex flex-wrap gap-2">{canManage && ["draft", "scheduled"].includes(selectedInvoice.status) && <Button variant="outline" onClick={() => setEditingInvoice((editing) => !editing)}>{editingInvoice ? "Cerrar edición" : "Editar"}</Button>}{canManage && selectedInvoice.status === "draft" && <Button disabled={busy || !settings?.enabled} onClick={() => void runInvoiceAction("issue")}>Emitir y enviar</Button>}{canManage && selectedInvoice.delivery_status === "failed" && <Button disabled={busy} onClick={() => void runInvoiceAction("resend")}>Reintentar envío</Button>}{selectedInvoice.status === "issued" && <Button variant="outline" onClick={() => void downloadPdf(selectedInvoice.id)}><ArrowDownToLine className="mr-2 size-4"/>Descargar PDF</Button>}</div>
          {editingInvoice && canManage && <form onSubmit={saveInvoiceEdits} className="mt-4 grid gap-3 rounded-xl border p-4 sm:grid-cols-2"><label className="space-y-1 text-xs sm:col-span-2">Concepto<Input required maxLength={500} value={editForm.concept} onChange={(event) => setEditForm({ ...editForm, concept: event.target.value })} /></label><label className="space-y-1 text-xs">Importe (ARS)<Input required type="number" min="0.01" step="0.01" value={editForm.amount} onChange={(event) => setEditForm({ ...editForm, amount: event.target.value })} /></label>{selectedInvoice.status === "scheduled" && <label className="space-y-1 text-xs">Fecha y hora de emisión<Input required type="datetime-local" value={editForm.scheduled_at} onChange={(event) => setEditForm({ ...editForm, scheduled_at: event.target.value })} /></label>}<div className="flex justify-end sm:col-span-2"><Button disabled={busy}>{busy ? "Guardando…" : "Guardar cambios"}</Button></div></form>}
          {canPay && selectedInvoice.status === "issued" && (selectedInvoice.balance_cents ?? 0) > 0 && <form onSubmit={submitPayment} className="mt-6 space-y-3 rounded-xl border p-4"><h3 className="font-medium">Registrar un pago</h3><div className="grid gap-3 sm:grid-cols-3"><label className="space-y-1 text-xs">Importe (ARS)<Input required type="number" min="0.01" step="0.01" max={(selectedInvoice.balance_cents ?? 0) / 100} value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} /></label><label className="space-y-1 text-xs">Fecha<Input required type="date" value={paymentDate} onChange={(event) => setPaymentDate(event.target.value)} /></label><label className="space-y-1 text-xs">Medio<Input value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)} placeholder="Transferencia" /></label></div><div className="flex justify-end"><Button disabled={busy}>Guardar pago</Button></div></form>}
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
