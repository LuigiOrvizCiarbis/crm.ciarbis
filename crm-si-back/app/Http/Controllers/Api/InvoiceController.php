<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoicePayment;
use App\Models\InvoiceSetting;
use App\Automation\AutomationRuleService;
use App\Enums\TemplateCategory;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeInvoice($request, 'invoices.view');
        $query = Invoice::with('contact')->withSum(['payments as paid_cents' => fn ($q) => $q->whereNull('reversed_at')], 'amount_cents')->latest('id');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('q')) $query->where(fn ($q) => $q->where('number', 'ilike', '%'.$request->string('q').'%')->orWhere('concept', 'ilike', '%'.$request->string('q').'%')->orWhereHas('contact', fn ($c) => $c->where('name', 'ilike', '%'.$request->string('q').'%')));
        return $query->paginate(min(100, max(10, (int) $request->input('per_page', 25))));
    }

    public function store(Request $request, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.manage');
        $data = $request->validate([
            'contact_id' => ['required', 'integer'], 'concept' => ['required', 'string', 'max:500'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'status' => ['nullable', 'in:draft,issued,scheduled'], 'scheduled_at' => ['nullable', 'date'],
        ]);
        if (($data['status'] ?? '') === 'scheduled' && empty($data['scheduled_at'])) throw ValidationException::withMessages(['scheduled_at' => 'Elegí fecha y hora para programar el envío.']);
        return response()->json(['data' => $service->create((int) $request->user()->tenant_id, $request->user(), $data)], 201);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.view');
        $this->sameTenant($request, $invoice);
        return ['data' => array_merge($invoice->load(['contact', 'payments', 'events', 'recurrence'])->toArray(), ['paid_cents' => $invoice->paidCents(), 'balance_cents' => $invoice->balanceCents(), 'payment_state' => $invoice->paymentState()])];
    }

    public function update(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.manage'); $this->sameTenant($request, $invoice);
        if (! in_array($invoice->status, ['draft', 'scheduled'], true)) throw ValidationException::withMessages(['invoice' => 'Un invoice emitido se corrige anulándolo y creando uno nuevo.']);
        $data = $request->validate([
            'contact_id' => ['sometimes', 'integer'], 'concept' => ['sometimes', 'string', 'max:500'],
            'amount_cents' => ['sometimes', 'integer', 'min:1', 'max:100000000000'], 'scheduled_at' => ['nullable', 'date'],
        ]);
        if (isset($data['contact_id'])) Contact::where('tenant_id', $invoice->tenant_id)->findOrFail($data['contact_id']);
        if ($invoice->status === 'scheduled' && array_key_exists('scheduled_at', $data) && empty($data['scheduled_at'])) throw ValidationException::withMessages(['scheduled_at' => 'Elegí fecha y hora para programar la emisión.']);
        $invoice->update($data);
        return ['data' => $invoice->fresh('contact')];
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.manage'); $this->sameTenant($request, $invoice);
        return ['data' => $service->issue($invoice, $request->user())];
    }

    public function resend(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.manage'); $this->sameTenant($request, $invoice);
        abort_unless($invoice->status === 'issued' && $invoice->delivery_status === 'failed', 409);
        $invoice->update(['delivery_status' => 'pending', 'delivery_error' => null]);
        \App\Jobs\SendInvoiceWhatsAppJob::dispatch($invoice->id, $invoice->tenant_id);
        return ['data' => $invoice->fresh()];
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.view'); $this->sameTenant($request, $invoice);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);
        return Storage::disk('local')->download($invoice->pdf_path, $invoice->number.'.pdf');
    }

    public function pay(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.payments'); $this->sameTenant($request, $invoice);
        $data = $request->validate(['amount_cents' => ['required', 'integer', 'min:1'], 'paid_on' => ['required', 'date'], 'method' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:2000']]);
        $payment = DB::transaction(function () use ($request, $invoice, $data, $service) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'issued') throw ValidationException::withMessages(['invoice' => 'Solo se pueden registrar pagos en cobros emitidos.']);
            $balance = $locked->balanceCents();
            if ($data['amount_cents'] > $balance) throw ValidationException::withMessages(['amount_cents' => 'El pago supera el saldo pendiente.']);
            $payment = $locked->payments()->create(['tenant_id' => $locked->tenant_id, ...$data, 'created_by' => $request->user()->id]);
            $service->event($locked, 'payment_recorded', $request->user(), ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents]);
            if ($locked->balanceCents() === 0) $locked->update(['next_reminder_at' => null]);
            return $payment;
        });
        return response()->json(['data' => $payment], 201);
    }

    public function reversePayment(Request $request, Invoice $invoice, InvoicePayment $payment, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.payments'); $this->sameTenant($request, $invoice);
        abort_unless($payment->invoice_id === $invoice->id && $payment->tenant_id === $invoice->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($request, $invoice, $payment, $data, $service) {
            // All balance-changing operations lock the invoice first, then its payment rows.
            $lockedInvoice = Invoice::where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = $lockedInvoice->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($lockedPayment->reversed_at) {
                throw ValidationException::withMessages(['payment' => 'El pago ya fue revertido.']);
            }

            $lockedPayment->update(['reversed_at' => now(), 'reversal_reason' => $data['reason'], 'reversed_by' => $request->user()->id]);
            if ($lockedInvoice->status === 'issued' && $lockedInvoice->delivery_status === 'delivered' && $lockedInvoice->balanceCents() > 0) {
                $lockedInvoice->update(['next_reminder_at' => now()]);
            }
            $service->event($lockedInvoice, 'payment_reversed', $request->user(), ['payment_id' => $lockedPayment->id, 'reason' => $data['reason']]);
        });
        return ['data' => $payment->fresh()];
    }

    public function void(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.manage'); $this->sameTenant($request, $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        if ($invoice->payments()->whereNull('reversed_at')->exists()) throw ValidationException::withMessages(['invoice' => 'Revertí los pagos antes de anular el cobro.']);
        $invoice->update(['status' => 'void', 'void_reason' => $data['reason'], 'next_reminder_at' => null]);
        $service->event($invoice, 'voided', $request->user(), ['reason' => $data['reason']]);
        return ['data' => $invoice->fresh()];
    }

    public function settings(Request $request)
    {
        $this->authorizeInvoice($request, 'invoices.view');
        $settings = InvoiceSetting::firstOrCreate(['tenant_id' => $request->user()->tenant_id]);
        $templates = collect();
        if ($settings->whatsapp_channel_id) {
            $channel = \App\Models\Channel::with('whatsappConfig')->find($settings->whatsapp_channel_id);
            if ($channel?->whatsappConfig) $templates = \App\Models\WhatsAppTemplate::where('whatsapp_config_id', $channel->whatsapp_config_id)->approved()->orderBy('name')->get()->map(fn ($template) => ['id' => $template->id, 'name' => $template->name, 'category' => $template->category->value ?? $template->category, 'header_format' => $template->headerMediaFormat(), 'parameters' => $template->expectedBodyParameters()]);
        }
        return ['data' => $settings, 'templates' => $templates];
    }

    public function updateSettings(Request $request, InvoiceService $service, AutomationRuleService $automationRules)
    {
        $this->authorizeInvoice($request, 'invoices.configure');
        $data = $request->validate(['business_name' => ['nullable', 'string', 'max:200'], 'payment_instructions' => ['nullable', 'string', 'max:3000'], 'whatsapp_channel_id' => ['nullable', 'integer'], 'whatsapp_template_id' => ['nullable', 'integer'], 'reminder_template_id' => ['nullable', 'integer'], 'timezone' => ['required', 'timezone:all'], 'payment_term_days' => ['required', 'integer', 'min:0', 'max:365'], 'send_hour' => ['required', 'integer', 'min:0', 'max:23'], 'reminder_days' => ['required', 'array', 'max:5'], 'reminder_days.*' => ['integer', 'min:0', 'max:365'], 'enabled' => ['boolean']]);
        $tenantId = (int) $request->user()->tenant_id;
        if (! empty($data['whatsapp_channel_id'])) {
            $valid = \App\Models\Channel::where('tenant_id', $tenantId)->whereKey($data['whatsapp_channel_id'])->where('type', \App\Enums\ChannelType::WHATSAPP)->exists();
            if (! $valid) throw ValidationException::withMessages(['whatsapp_channel_id' => 'Elegí un canal de WhatsApp del workspace.']);
        }
        if (! empty($data['whatsapp_template_id']) || ! empty($data['reminder_template_id'])) {
            $channel = \App\Models\Channel::with('whatsappConfig')->where('tenant_id', $tenantId)->find($data['whatsapp_channel_id'] ?? null);
            foreach (['whatsapp_template_id' => 'DOCUMENT', 'reminder_template_id' => null] as $key => $format) {
                if (empty($data[$key])) continue;
                $template = \App\Models\WhatsAppTemplate::where('tenant_id', $tenantId)->where('whatsapp_config_id', $channel?->whatsapp_config_id)->approved()->find($data[$key]);
                if (! $template || $template->category !== TemplateCategory::Utility || ($format && $template->headerMediaFormat() !== $format) || ($key === 'reminder_template_id' && $template->headerMediaFormat() !== null)) throw ValidationException::withMessages([$key => $format ? 'Elegí una plantilla Utility aprobada con encabezado de documento.' : 'Elegí una plantilla Utility aprobada sin encabezado de archivo para recordatorios.']);
            }
        }
        $settings = InvoiceSetting::firstOrNew(['tenant_id' => $tenantId]);
        $wasEnabled = $settings->enabled;
        $settings->fill($data);
        if (($data['enabled'] ?? false) && (! $settings->business_name || ! $settings->whatsapp_channel_id || ! $settings->whatsapp_template_id || ! $settings->reminder_template_id)) throw ValidationException::withMessages(['enabled' => 'Completá el nombre del negocio, elegí el canal y configurá las plantillas de invoice y recordatorio antes de activar Invoices.']);
        $settings->save();
        if (! $wasEnabled && $settings->enabled) {
            // The previous contact-based collection engine must not double-send.
            \App\Models\BillingConfig::where('tenant_id', $tenantId)->update(['enabled' => false]);
            \App\Models\AutomationRule::where('tenant_id', $tenantId)->where('name', 'like', 'Cobranzas:%')->where('status', 'active')->get()->each(fn ($rule) => $automationRules->pause($rule));
        }
        return ['data' => $settings->fresh()];
    }

    private function authorizeInvoice(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    private function sameTenant(Request $request, Invoice $invoice): void
    {
        abort_unless((int) $invoice->tenant_id === (int) $request->user()->tenant_id, 404);
    }
}
