<?php

namespace App\Services;

use App\Jobs\SendInvoiceWhatsAppJob;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InvoiceService
{
    public function create(int $tenantId, User $user, array $data): Invoice
    {
        return DB::transaction(function () use ($tenantId, $user, $data): Invoice {
            $contact = Contact::where('tenant_id', $tenantId)->findOrFail($data['contact_id']);
            $requestedStatus = $data['status'] ?? 'draft';
            // Issue after creation so issue() can perform its state transition,
            // generate the PDF and dispatch the WhatsApp delivery job.
            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'contact_id' => $contact->id,
                'number' => 'pending',
                'concept' => $data['concept'],
                'amount_cents' => $data['amount_cents'],
                'currency' => 'ARS',
                'status' => $requestedStatus === 'issued' ? 'draft' : $requestedStatus,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'created_by' => $user->id,
            ]);
            $invoice->update(['number' => 'INV-'.str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT)]);
            $this->event($invoice, 'created', $user, ['status' => $invoice->status]);

            if ($requestedStatus === 'issued') {
                $this->issue($invoice, $user);
            }

            return $invoice->fresh(['contact', 'payments', 'events']);
        });
    }

    public function issue(Invoice $invoice, ?User $user = null): Invoice
    {
        if ($invoice->status !== 'draft' && $invoice->status !== 'scheduled') {
            return $invoice;
        }
        $contact = Contact::where('tenant_id', $invoice->tenant_id)->findOrFail($invoice->contact_id);
        $settings = InvoiceSetting::firstOrCreate(['tenant_id' => $invoice->tenant_id]);
        if (! $settings->enabled || ! $settings->whatsapp_channel_id || ! $settings->whatsapp_template_id) {
            throw ValidationException::withMessages(['invoice' => 'Activá Invoices y configurá canal y plantilla aprobada antes de emitir un cobro.']);
        }
        $today = $invoice->issued_on?->format('Y-m-d') ?? now($settings->timezone)->toDateString();
        $issuer = ['name' => $settings->business_name ?: $invoice->tenant?->name, 'instructions' => $settings->payment_instructions];
        $customer = ['name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email];

        $invoice->forceFill([
            'status' => 'issued',
            'issued_on' => $today,
            'due_on' => CarbonImmutable::parse($today, $settings->timezone)->addDays((int) ($invoice->recurrence?->payment_term_days ?? $settings->payment_term_days))->toDateString(),
            'issuer_snapshot' => $issuer,
            'contact_snapshot' => $customer,
        ]);

        $path = "invoices/{$invoice->tenant_id}/{$invoice->number}.pdf";
        $invoice->pdf_path = $path;
        $invoice->delivery_status = 'pending';
        $invoice->save();
        $this->ensurePdfExists($invoice);
        $this->event($invoice, 'issued', $user, ['issued_on' => $today, 'due_on' => $invoice->due_on->format('Y-m-d')]);
        SendInvoiceWhatsAppJob::dispatch($invoice->id, $invoice->tenant_id)->afterCommit();

        return $invoice->fresh();
    }

    public function ensurePdfExists(Invoice $invoice): string
    {
        $disk = Storage::disk('local');
        $path = $invoice->pdf_path ?: "invoices/{$invoice->tenant_id}/{$invoice->number}.pdf";

        if (! $disk->exists($path)) {
            $settings = InvoiceSetting::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->first();
            $contact = Contact::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->find($invoice->contact_id);
            $issuer = array_replace([
                'name' => $settings?->business_name ?: $invoice->tenant?->name,
                'instructions' => $settings?->payment_instructions,
            ], $invoice->issuer_snapshot ?? []);
            $customer = array_replace([
                'name' => $contact?->name ?? '',
                'phone' => $contact?->phone ?? '',
                'email' => $contact?->email ?? '',
            ], $invoice->contact_snapshot ?? []);
            $contents = Pdf::loadView('invoices.pdf', compact('invoice', 'issuer', 'customer'))->output();

            if (! $disk->put($path, $contents)) {
                throw new RuntimeException("No se pudo guardar el PDF del invoice {$invoice->number}.");
            }
        }

        if ($invoice->pdf_path !== $path) {
            $invoice->forceFill(['pdf_path' => $path])->save();
        }

        return $path;
    }

    public function event(Invoice $invoice, string $type, ?User $user, array $details = []): void
    {
        InvoiceEvent::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'user_id' => $user?->id, 'type' => $type, 'details' => $details]);
    }
}
