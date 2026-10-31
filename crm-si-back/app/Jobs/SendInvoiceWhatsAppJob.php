<?php

namespace App\Jobs;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceSetting;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastConversationResolver;
use App\Services\InvoiceService;
use App\Services\WhatsAppTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendInvoiceWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 90;

    public function __construct(public int $invoiceId, public int $tenantId) {}

    public function handle(WhatsAppTemplateService $templates, BroadcastConversationResolver $conversations, InvoiceService $invoiceService): void
    {
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->invoiceId);
        if (! $invoice || $invoice->status !== 'issued' || $invoice->delivery_status === 'delivered') {
            return;
        }
        $settings = InvoiceSetting::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->first();
        $channel = $settings?->whatsapp_channel_id
            ? Channel::withoutGlobalScopes()->with('whatsappConfig')->where('tenant_id', $this->tenantId)->find($settings->whatsapp_channel_id)
            : null;
        $template = $settings?->whatsapp_template_id
            ? WhatsAppTemplate::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($settings->whatsapp_template_id)
            : null;
        $contact = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($invoice->contact_id);
        if (! $channel || ! $channel->isActive() || ! $template || $template->status !== TemplateStatus::Approved || $template->category !== TemplateCategory::Utility || $template->headerMediaFormat() !== 'DOCUMENT' || ! $contact?->phone) {
            $this->failInvoice($invoice, 'Falta un canal activo, teléfono, PDF o plantilla Utility aprobada con encabezado PDF.');

            return;
        }

        $pdfPath = $invoiceService->ensurePdfExists($invoice);
        $path = Storage::disk('local')->path($pdfPath);
        if (! is_file($path)) {
            throw new \RuntimeException("No se encontró el PDF generado para el invoice {$invoice->number}.");
        }
        $file = new UploadedFile($path, $invoice->number.'.pdf', 'application/pdf', null, true);
        $mediaId = $templates->uploadMedia($channel->whatsappConfig, $file);
        $conversation = $conversations->findOrCreate($contact, $channel);
        $values = [
            'cliente' => $contact->name,
            'invoice' => $invoice->number,
            'numero' => $invoice->number,
            'concepto' => $invoice->concept,
            'importe' => '$ '.number_format($invoice->amount_cents / 100, 2, ',', '.').' ARS',
            'fecha' => $invoice->due_on?->format('d/m/Y'),
            'vencimiento' => $invoice->due_on?->format('d/m/Y'),
            'saldo' => '$ '.number_format($invoice->balanceCents() / 100, 2, ',', '.').' ARS',
        ];
        $positionalValues = array_values($values);
        $bodyParameters = [];
        foreach ($template->expectedBodyParameters() as $key) {
            $value = ctype_digit($key) ? ($positionalValues[((int) $key) - 1] ?? null) : ($values[$key] ?? null);
            if ($value === null) {
                $this->failInvoice($invoice, "La plantilla requiere una variable no soportada: {$key}.");

                return;
            }
            $bodyParameters[] = ['type' => 'text', ...(ctype_digit($key) ? [] : ['parameter_name' => $key]), 'text' => (string) $value];
        }
        $components = [
            ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $mediaId, 'filename' => $invoice->number.'.pdf']]]],
        ];
        if ($bodyParameters) {
            $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
        }

        try {
            $message = $templates->sendSystemTemplateMessage($conversation, $template, $components);
        } catch (ConnectionException $exception) {
            // A timeout after POST may mean Meta accepted the message. Never
            // retry automatically or expose a manual retry for this state.
            $invoice->update(['delivery_status' => 'unknown', 'delivery_error' => 'Meta no confirmó si recibió el mensaje. Verificá WhatsApp antes de reenviar.']);
            InvoiceEvent::create(['tenant_id' => $this->tenantId, 'invoice_id' => $invoice->id, 'type' => 'message_unknown', 'details' => ['error' => mb_substr($exception->getMessage(), 0, 500)]]);

            return;
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '429') || preg_match('/\b5\d\d\b/', $exception->getMessage())) {
                throw $exception;
            }
            $this->failInvoice($invoice, $exception->getMessage());

            return;
        }
        $invoice->update(['delivery_status' => 'accepted', 'sent_at' => now(), 'delivery_error' => null]);
        InvoiceEvent::create(['tenant_id' => $this->tenantId, 'invoice_id' => $invoice->id, 'type' => 'message_accepted', 'details' => ['message_id' => $message->id, 'external_id' => $message->external_id, 'conversation_id' => $conversation->id]]);
    }

    public function failed(?Throwable $exception): void
    {
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->invoiceId);
        $this->failInvoice($invoice, $exception?->getMessage() ?? 'No se pudo enviar el invoice.');
    }

    private function failInvoice(?Invoice $invoice, string $reason): void
    {
        if (! $invoice) {
            return;
        }
        $invoice->update(['delivery_status' => 'failed', 'delivery_error' => mb_substr($reason, 0, 250)]);
        InvoiceEvent::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'type' => 'message_failed', 'details' => ['error' => mb_substr($reason, 0, 500)]]);
    }
}
