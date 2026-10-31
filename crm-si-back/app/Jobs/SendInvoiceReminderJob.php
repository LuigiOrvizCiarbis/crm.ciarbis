<?php

namespace App\Jobs;

use App\Enums\TemplateStatus;
use App\Enums\TemplateCategory;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceSetting;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastConversationResolver;
use App\Services\WhatsAppTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendInvoiceReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public function __construct(public int $invoiceId, public int $tenantId) {}

    public function handle(WhatsAppTemplateService $service, BroadcastConversationResolver $resolver): void
    {
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->invoiceId);
        if (! $invoice || $invoice->status !== 'issued' || $invoice->balanceCents() <= 0 || $invoice->delivery_status !== 'delivered') return;
        $settings = InvoiceSetting::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->first();
        $channel = $settings?->whatsapp_channel_id ? Channel::withoutGlobalScopes()->with('whatsappConfig')->where('tenant_id', $this->tenantId)->find($settings->whatsapp_channel_id) : null;
        $template = $settings?->reminder_template_id ? WhatsAppTemplate::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($settings->reminder_template_id) : null;
        $contact = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($invoice->contact_id);
        if (! $channel || ! $channel->isActive() || ! $template || $template->status !== TemplateStatus::Approved || $template->category !== TemplateCategory::Utility || $template->headerMediaFormat() !== null || ! $contact?->phone) {
            $this->failEvent($invoice, 'La configuración del canal, cliente o plantilla de recordatorio no está disponible.'); return;
        }
        $values = ['cliente' => $contact->name, 'invoice' => $invoice->number, 'numero' => $invoice->number, 'vencimiento' => $invoice->due_on?->format('d/m/Y'), 'fecha' => $invoice->due_on?->format('d/m/Y'), 'saldo' => '$ '.number_format($invoice->balanceCents() / 100, 2, ',', '.').' ARS'];
        $positionalValues = [$contact->name, $invoice->number, $invoice->due_on?->format('d/m/Y'), '$ '.number_format($invoice->balanceCents() / 100, 2, ',', '.').' ARS'];
        $parameters = [];
        foreach ($template->expectedBodyParameters() as $key) {
            $value = ctype_digit($key) ? ($positionalValues[((int) $key) - 1] ?? null) : ($values[$key] ?? null);
            if ($value === null) { $this->failEvent($invoice, "Variable de plantilla no soportada: {$key}."); return; }
            $parameters[] = ['type' => 'text', ...(ctype_digit($key) ? [] : ['parameter_name' => $key]), 'text' => (string) $value];
        }
        $components = $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [];
        $message = $service->sendSystemTemplateMessage($resolver->findOrCreate($contact, $channel), $template, $components);

        $days = array_values(array_map('intval', $settings->reminder_days ?? [0, 3, 7]));
        sort($days);
        $sent = $invoice->reminders_sent + 1;
        $next = $days[$sent] ?? null;
        $invoice->update(['reminders_sent' => $sent, 'next_reminder_at' => $next === null ? null : now($settings->timezone)->parse((string) $invoice->due_on)->addDays($next)->setTime($settings->send_hour, 0)->utc()]);
        InvoiceEvent::create(['tenant_id' => $this->tenantId, 'invoice_id' => $invoice->id, 'type' => 'reminder_sent', 'details' => ['message_id' => $message->id, 'balance_cents' => $invoice->balanceCents(), 'reminder_number' => $sent]]);
    }

    public function failed(?Throwable $exception): void
    {
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->invoiceId);
        if (! $invoice) return;

        InvoiceEvent::create([
            'tenant_id' => $this->tenantId,
            'invoice_id' => $invoice->id,
            'type' => 'reminder_failed',
            'details' => ['error' => mb_substr($exception?->getMessage() ?? 'No se pudo enviar el recordatorio.', 0, 500)],
        ]);

        if ($invoice->status !== 'issued' || $invoice->balanceCents() <= 0 || $invoice->delivery_status !== 'delivered') return;

        $settings = InvoiceSetting::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->first();
        $timezone = $settings?->timezone ?? 'America/Argentina/Buenos_Aires';
        $sendHour = $settings?->send_hour ?? 9;
        $retryAt = now($timezone)->addDay()->setTime($sendHour, 0);
        if ($retryAt->isPast()) $retryAt = $retryAt->addDay();

        $invoice->update(['next_reminder_at' => $retryAt->utc()]);
    }

    private function failEvent(Invoice $invoice, string $reason): void
    {
        InvoiceEvent::create(['tenant_id' => $this->tenantId, 'invoice_id' => $invoice->id, 'type' => 'reminder_failed', 'details' => ['error' => mb_substr($reason, 0, 500)]]);
        $invoice->update(['next_reminder_at' => null]);
    }
}
