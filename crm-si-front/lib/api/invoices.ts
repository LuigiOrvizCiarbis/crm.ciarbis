import { getAuthToken, workspaceHeaders } from "@/lib/api/auth-token"
import { throwApiError } from "@/lib/api/api-error"

export interface InvoiceRecord {
  id: number; number: string; concept: string; amount_cents: number; currency: string; status: string
  delivery_status: string; due_on: string | null; scheduled_at: string | null; contact?: { id: number; name: string }
  paid_cents?: number; balance_cents?: number; payment_state?: string; collection_status?: InvoiceCollectionStatus | null
  payments?: Array<{ id: number; amount_cents: number; paid_on: string; method: string | null; note: string | null; reversed_at: string | null; reversal_reason: string | null }>
  events?: Array<{ id: number; type: string; details: Record<string, unknown>; created_at: string }>
}

export type InvoiceCollectionStatus = "pending" | "overdue" | "partial" | "partial_overdue" | "paid"

export interface InvoiceSummary {
  outstanding_cents: number
  overdue_balance_cents: number
  overdue_count: number
}

export interface InvoiceRecurrenceRecord {
  id: number; concept: string; amount_cents: number; interval_unit: string; interval_count: number
  next_occurrence_on: string | null; status: string; payment_term_days: number; contact?: { id: number; name: string }; invoices_count?: number
}
export interface InvoiceSettingsRecord {
  business_name: string | null; payment_instructions: string | null; whatsapp_channel_id: number | null
  whatsapp_template_id: number | null; reminder_template_id: number | null; timezone: string
  payment_term_days: number; reminder_days: number[]; enabled: boolean
  send_hour: number
}
export interface InvoiceTemplateProvisioningRecord {
  id: number; channel_id: number; state: string
  invoice: { id: number; name: string; status: string; rejected_reason: string | null } | null
  reminder: { id: number; name: string; status: string; rejected_reason: string | null } | null
  invoice_error: string | null; reminder_error: string | null
}

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const token = getAuthToken()
  if (!token) throw new Error("No hay sesión activa")
  const response = await fetch(path, { ...init, headers: { Authorization: `Bearer ${token}`, Accept: "application/json", "Content-Type": "application/json", ...workspaceHeaders(), ...init?.headers }, cache: "no-store" })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throwApiError(response.status, payload, "No se pudo completar la operación")
  return payload as T
}

export async function listInvoices(page = 1, search = "", collectionStatus = "all"): Promise<{ rows: InvoiceRecord[]; pages: number; summary: InvoiceSummary }> {
  const query = new URLSearchParams({ per_page: "50", page: String(page) })
  if (search) query.set("q", search)
  if (collectionStatus !== "all") query.set("collection_status", collectionStatus)
  const payload = await api<{ data: InvoiceRecord[]; meta?: { last_page: number }; last_page?: number; summary: InvoiceSummary }>(`/api/invoices?${query.toString()}`)
  return { rows: payload.data, pages: payload.meta?.last_page ?? payload.last_page ?? 1, summary: payload.summary }
}
export async function listInvoiceRecurrences(): Promise<InvoiceRecurrenceRecord[]> {
  const payload = await api<{ data: InvoiceRecurrenceRecord[] }>('/api/invoice-recurrences?per_page=50')
  return payload.data
}
export async function createInvoice(input: { contact_id: number; concept: string; amount_cents: number; status: string; scheduled_at?: string }): Promise<InvoiceRecord> {
  const payload = await api<{ data: InvoiceRecord }>('/api/invoices', { method: 'POST', body: JSON.stringify(input) })
  return payload.data
}
export async function createRecurrence(input: Record<string, unknown>): Promise<void> {
  await api('/api/invoice-recurrences', { method: 'POST', body: JSON.stringify(input) })
}
export async function updateRecurrence(id: number, input: Record<string, unknown>): Promise<void> {
  await api(`/api/invoice-recurrences/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function recurrenceAction(id: number, action: string): Promise<void> {
  await api(`/api/invoice-recurrences/${id}/${action}`, { method: 'POST', body: '{}' })
}
export async function getInvoiceSettings(): Promise<{ settings: InvoiceSettingsRecord; templates: Array<{ id: number; name: string; category: string; header_format: string | null; parameters: string[] }>; template_provisioning: InvoiceTemplateProvisioningRecord | null }> {
  const payload = await api<{ data: InvoiceSettingsRecord; templates: Array<{ id: number; name: string; category: string; header_format: string | null; parameters: string[] }>; template_provisioning: InvoiceTemplateProvisioningRecord | null }>('/api/invoices/settings')
  return { settings: payload.data, templates: payload.templates ?? [], template_provisioning: payload.template_provisioning ?? null }
}
export async function provisionInvoiceTemplates(channel_id: number): Promise<InvoiceTemplateProvisioningRecord> {
  const payload = await api<{ data: InvoiceTemplateProvisioningRecord }>('/api/invoices/templates/provision', { method: 'POST', body: JSON.stringify({ channel_id }) })
  return payload.data
}
export async function saveInvoiceSettings(settings: InvoiceSettingsRecord): Promise<void> {
  await api('/api/invoices/settings', { method: 'PUT', body: JSON.stringify(settings) })
}
export async function getInvoice(id: number): Promise<InvoiceRecord> {
  const payload = await api<{ data: InvoiceRecord }>(`/api/invoices/${id}`)
  return payload.data
}
export async function issueInvoice(id: number): Promise<void> { await api(`/api/invoices/${id}/issue`, { method: 'POST', body: '{}' }) }
export async function updateInvoice(id: number, input: { contact_id?: number; concept?: string; amount_cents?: number; scheduled_at?: string | null }): Promise<void> {
  await api(`/api/invoices/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function resendInvoice(id: number): Promise<void> { await api(`/api/invoices/${id}/resend`, { method: 'POST', body: '{}' }) }
export async function recordInvoicePayment(id: number, input: { amount_cents: number; paid_on: string; method?: string; note?: string }): Promise<void> {
  await api(`/api/invoices/${id}/payments`, { method: 'POST', body: JSON.stringify(input) })
}
export async function setInvoiceCollectionStatus(id: number, status: "pending" | "overdue" | "paid"): Promise<void> {
  await api(`/api/invoices/${id}/collection-status`, { method: "PUT", body: JSON.stringify({ status }) })
}
export async function voidInvoice(id: number, reason: string): Promise<void> { await api(`/api/invoices/${id}/void`, { method: 'POST', body: JSON.stringify({ reason }) }) }
export async function reverseInvoicePayment(invoiceId: number, paymentId: number, reason: string): Promise<void> {
  await api(`/api/invoices/${invoiceId}/payments/${paymentId}/reverse`, { method: 'POST', body: JSON.stringify({ reason }) })
}
