<?php

namespace App\Http\Controllers\Api;

use App\Automation\AutomationRuleService;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Http\Controllers\Controller;
use App\Jobs\ProvisionInvoiceTemplatesJob;
use App\Jobs\SendInvoiceWhatsAppJob;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceSetting;
use App\Models\InvoiceTemplateProvisioning;
use App\Models\WhatsAppTemplate;
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
        $tenantId = (int) $request->user()->tenant_id;
        $timezone = InvoiceSetting::where('tenant_id', $tenantId)->value('timezone') ?: 'America/Argentina/Buenos_Aires';
        $today = now($timezone)->toDateString();
        $paidTotals = InvoicePayment::query()
            ->select(['tenant_id', 'invoice_id'])
            ->selectRaw('SUM(amount_cents) AS paid_cents')
            ->where('invoice_payments.tenant_id', $tenantId)
            ->whereNull('reversed_at')
            ->groupBy(['tenant_id', 'invoice_id']);
        $paidExpression = 'COALESCE(payment_totals.paid_cents, 0)';
        $partialBalance = "{$paidExpression} > 0 AND {$paidExpression} < invoices.amount_cents";
        $query = Invoice::query()
            ->leftJoinSub($paidTotals, 'payment_totals', fn ($join) => $join
                ->on('payment_totals.invoice_id', '=', 'invoices.id')
                ->on('payment_totals.tenant_id', '=', 'invoices.tenant_id'))
            ->select('invoices.*')
            ->selectRaw("{$paidExpression} AS paid_cents")
            ->with('contact')
            ->latest('invoices.id');

        if ($request->filled('status')) {
            $query->where('invoices.status', $request->string('status'));
        }
        $request->validate(['invoice_recurrence_id' => ['nullable', 'integer', 'min:1']]);
        if ($request->filled('invoice_recurrence_id')) {
            $query->where('invoices.invoice_recurrence_id', (int) $request->input('invoice_recurrence_id'));
        }
        if ($request->filled('q')) {
            $search = '%'.mb_strtolower(trim((string) $request->string('q'))).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(invoices.number) LIKE ?', [$search])
                ->orWhereRaw('LOWER(invoices.concept) LIKE ?', [$search])
                ->orWhereHas('contact', fn ($contact) => $contact->whereRaw('LOWER(name) LIKE ?', [$search])));
        }

        $request->validate([
            'collection_status' => ['nullable', 'in:pending,overdue,partial,partial_overdue,paid'],
        ]);
        $query->when($request->filled('collection_status'), function ($query) use ($request, $paidExpression, $partialBalance, $today) {
            $collectionStatus = (string) $request->input('collection_status');
            $query->where('invoices.status', '!=', 'void');

            match ($collectionStatus) {
                'pending' => $query->whereRaw("{$paidExpression} = 0")
                    ->where(fn ($status) => $status->where('invoices.collection_status_override', 'pending')->orWhere(fn ($automatic) => $automatic->whereNull('invoices.collection_status_override')->where(fn ($due) => $due->whereNull('invoices.due_on')->orWhereDate('invoices.due_on', '>=', $today)))),
                'overdue' => $query->whereRaw("{$paidExpression} = 0")
                    ->where(fn ($status) => $status->where('invoices.collection_status_override', 'overdue')->orWhere(fn ($automatic) => $automatic->whereNull('invoices.collection_status_override')->whereDate('invoices.due_on', '<', $today))),
                'partial' => $query->whereRaw($partialBalance)
                    ->where(fn ($due) => $due->whereNull('invoices.due_on')->orWhereDate('invoices.due_on', '>=', $today)),
                'partial_overdue' => $query->whereRaw($partialBalance)->whereDate('invoices.due_on', '<', $today),
                'paid' => $query->whereRaw("{$paidExpression} >= invoices.amount_cents"),
            };
        });

        $paginator = $query->paginate(min(100, max(10, (int) $request->input('per_page', 25))));
        $paginator->getCollection()->transform(function (Invoice $invoice) use ($today) {
            $paid = (int) $invoice->paid_cents;
            $balance = max(0, (int) $invoice->amount_cents - $paid);
            $invoice->setAttribute('paid_cents', $paid);
            $invoice->setAttribute('balance_cents', $balance);
            $invoice->setAttribute('payment_state', $invoice->paymentState($paid));
            $invoice->setAttribute('collection_status', $invoice->collectionStatus($paid, $today));

            return $invoice;
        });

        $summary = Invoice::query()
            ->leftJoinSub($paidTotals, 'payment_totals', fn ($join) => $join
                ->on('payment_totals.invoice_id', '=', 'invoices.id')
                ->on('payment_totals.tenant_id', '=', 'invoices.tenant_id'))
            ->where('invoices.status', '!=', 'void')
            ->selectRaw("COALESCE(SUM(CASE WHEN invoices.amount_cents > {$paidExpression} THEN invoices.amount_cents - {$paidExpression} ELSE 0 END), 0) AS outstanding_cents")
            ->selectRaw("COALESCE(SUM(CASE WHEN (invoices.collection_status_override = 'overdue' OR (invoices.collection_status_override IS NULL AND invoices.due_on < ?)) AND invoices.amount_cents > {$paidExpression} THEN invoices.amount_cents - {$paidExpression} ELSE 0 END), 0) AS overdue_balance_cents", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN (invoices.collection_status_override = 'overdue' OR (invoices.collection_status_override IS NULL AND invoices.due_on < ?)) AND {$paidExpression} < invoices.amount_cents THEN 1 ELSE 0 END), 0) AS overdue_count", [$today])
            ->first();

        return [...$paginator->toArray(), 'summary' => [
            'outstanding_cents' => (int) $summary->outstanding_cents,
            'overdue_balance_cents' => (int) $summary->overdue_balance_cents,
            'overdue_count' => (int) $summary->overdue_count,
        ]];
    }

    public function store(Request $request, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.manage');
        $data = $request->validate([
            'contact_id' => ['required', 'integer'], 'concept' => ['required', 'string', 'max:500'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'status' => ['nullable', 'in:draft,issued,scheduled'], 'scheduled_at' => ['nullable', 'date'],
        ]);
        if (($data['status'] ?? '') === 'scheduled' && empty($data['scheduled_at'])) {
            throw ValidationException::withMessages(['scheduled_at' => 'Elegí fecha y hora para programar el envío.']);
        }

        return response()->json(['data' => $service->create((int) $request->user()->tenant_id, $request->user(), $data)], 201);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.view');
        $this->sameTenant($request, $invoice);

        $paidCents = $invoice->paidCents();
        $timezone = InvoiceSetting::where('tenant_id', $invoice->tenant_id)->value('timezone') ?: 'America/Argentina/Buenos_Aires';
        $today = now($timezone)->toDateString();

        return ['data' => array_merge($invoice->load(['contact', 'payments', 'events', 'recurrence'])->toArray(), [
            'paid_cents' => $paidCents,
            'balance_cents' => max(0, $invoice->amount_cents - $paidCents),
            'payment_state' => $invoice->paymentState($paidCents),
            'collection_status' => $invoice->collectionStatus($paidCents, $today),
        ])];
    }

    public function update(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.manage');
        $this->sameTenant($request, $invoice);
        if (! in_array($invoice->status, ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Un invoice emitido se corrige anulándolo y creando uno nuevo.']);
        }
        $data = $request->validate([
            'contact_id' => ['sometimes', 'integer'], 'concept' => ['sometimes', 'string', 'max:500'],
            'amount_cents' => ['sometimes', 'integer', 'min:1', 'max:100000000000'], 'scheduled_at' => ['nullable', 'date'],
        ]);
        if (isset($data['contact_id'])) {
            Contact::where('tenant_id', $invoice->tenant_id)->findOrFail($data['contact_id']);
        }
        if ($invoice->status === 'scheduled' && array_key_exists('scheduled_at', $data) && empty($data['scheduled_at'])) {
            throw ValidationException::withMessages(['scheduled_at' => 'Elegí fecha y hora para programar la emisión.']);
        }
        DB::transaction(function () use ($invoice, $data): void {
            $locked = Invoice::where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'scheduled'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Un invoice emitido se corrige anulándolo y creando uno nuevo.']);
            }
            if (isset($data['amount_cents']) && $data['amount_cents'] < $locked->paidCents()) {
                throw ValidationException::withMessages(['amount_cents' => 'El importe no puede ser menor que los pagos ya registrados.']);
            }
            $locked->update($data);
        });

        return ['data' => $invoice->fresh('contact')];
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.manage');
        $this->sameTenant($request, $invoice);

        return ['data' => $service->issue($invoice, $request->user())];
    }

    public function resend(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.manage');
        $this->sameTenant($request, $invoice);
        abort_unless($invoice->status === 'issued' && $invoice->delivery_status === 'failed', 409);
        $invoice->update(['delivery_status' => 'pending', 'delivery_error' => null]);
        SendInvoiceWhatsAppJob::dispatch($invoice->id, $invoice->tenant_id);

        return ['data' => $invoice->fresh()];
    }

    public function pdf(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.view');
        $this->sameTenant($request, $invoice);
        abort_unless($invoice->status === 'issued', 404);
        $path = $service->ensurePdfExists($invoice);

        return Storage::disk('local')->download($path, $invoice->number.'.pdf');
    }

    public function pay(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.payments');
        $this->sameTenant($request, $invoice);
        $data = $request->validate(['amount_cents' => ['required', 'integer', 'min:1'], 'paid_on' => ['required', 'date'], 'method' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:2000']]);
        $payment = DB::transaction(function () use ($request, $invoice, $data, $service) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'scheduled', 'issued'], true)) {
                throw ValidationException::withMessages(['invoice' => 'No se pueden registrar pagos en cobros anulados.']);
            }
            $balance = $locked->balanceCents();
            if ($data['amount_cents'] > $balance) {
                throw ValidationException::withMessages(['amount_cents' => 'El pago supera el saldo pendiente.']);
            }
            $payment = $locked->payments()->create(['tenant_id' => $locked->tenant_id, ...$data, 'created_by' => $request->user()->id]);
            $service->event($locked, 'payment_recorded', $request->user(), ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents]);
            if ($locked->balanceCents() === 0) {
                $locked->update(['next_reminder_at' => null]);
            }

            return $payment;
        });

        return response()->json(['data' => $payment], 201);
    }

    public function setCollectionStatus(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request, 'invoices.payments');
        $this->sameTenant($request, $invoice);
        $data = $request->validate(['status' => ['required', 'in:pending,overdue,paid']]);

        DB::transaction(function () use ($request, $invoice, $data): void {
            $locked = Invoice::where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['invoice' => 'No se puede cambiar el estado de un cobro anulado.']);
            }

            $paidCents = $locked->paidCents();
            if ($data['status'] === 'paid') {
                $balance = $locked->balanceCents();
                if ($balance > 0) {
                    $timezone = InvoiceSetting::where('tenant_id', $locked->tenant_id)->value('timezone') ?: 'America/Argentina/Buenos_Aires';
                    $payment = $locked->payments()->create([
                        'tenant_id' => $locked->tenant_id,
                        'amount_cents' => $balance,
                        'paid_on' => now($timezone)->toDateString(),
                        'method' => 'Registro manual',
                        'note' => 'Pago marcado como pagado desde el selector de estado.',
                        'created_by' => $request->user()->id,
                    ]);
                    app(InvoiceService::class)->event($locked, 'payment_recorded', $request->user(), [
                        'payment_id' => $payment->id,
                        'amount_cents' => $payment->amount_cents,
                        'source' => 'collection_status_selector',
                    ]);
                }
                $locked->update(['next_reminder_at' => null]);

                return;
            }

            if ($paidCents > 0) {
                throw ValidationException::withMessages(['status' => 'Revertí los pagos parciales antes de marcar el cobro como pendiente o impago.']);
            }

            $previousStatus = $locked->collection_status_override;
            $locked->update(['collection_status_override' => $data['status']]);
            app(InvoiceService::class)->event($locked, 'collection_status_updated', $request->user(), [
                'from' => $previousStatus,
                'to' => $data['status'],
            ]);
        });

        return response()->noContent();
    }

    public function reversePayment(Request $request, Invoice $invoice, InvoicePayment $payment, InvoiceService $service)
    {
        $this->authorizeInvoice($request, 'invoices.payments');
        $this->sameTenant($request, $invoice);
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
        $this->authorizeInvoice($request, 'invoices.manage');
        $this->sameTenant($request, $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($invoice, $request, $data, $service): void {
            $locked = Invoice::where('tenant_id', $invoice->tenant_id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->payments()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Revertí los pagos antes de anular el cobro.']);
            }
            $locked->update(['status' => 'void', 'void_reason' => $data['reason'], 'next_reminder_at' => null]);
            $service->event($locked, 'voided', $request->user(), ['reason' => $data['reason']]);
        });

        return ['data' => $invoice->fresh()];
    }

    public function settings(Request $request)
    {
        $this->authorizeInvoice($request, 'invoices.view');
        $settings = InvoiceSetting::firstOrCreate(['tenant_id' => $request->user()->tenant_id]);
        $templates = collect();
        if ($settings->whatsapp_channel_id) {
            $channel = Channel::with('whatsappConfig')->find($settings->whatsapp_channel_id);
            if ($channel?->whatsappConfig) {
                $templates = WhatsAppTemplate::where('whatsapp_config_id', $channel->whatsapp_config_id)->approved()->orderBy('name')->get()->map(fn ($template) => ['id' => $template->id, 'name' => $template->name, 'category' => $template->category->value ?? $template->category, 'header_format' => $template->headerMediaFormat(), 'parameters' => $template->expectedBodyParameters()]);
            }
        }
        $provisioning = $settings->whatsapp_channel_id
            ? InvoiceTemplateProvisioning::where('tenant_id', $settings->tenant_id)->where('channel_id', $settings->whatsapp_channel_id)->latest('id')->first()
            : null;

        return ['data' => $settings, 'templates' => $templates, 'template_provisioning' => $provisioning?->toStatusArray()];
    }

    public function provisionTemplates(Request $request)
    {
        $this->authorizeInvoice($request, 'invoices.configure');
        abort_unless($request->user()?->can('templates.create'), 403);
        $data = $request->validate(['channel_id' => ['required', 'integer']]);
        $tenantId = (int) $request->user()->tenant_id;
        $channel = Channel::with('whatsappConfig')->where('tenant_id', $tenantId)->whereKey($data['channel_id'])->where('type', ChannelType::WHATSAPP)->firstOrFail();
        abort_unless($channel->isActive() && $channel->whatsappConfig && $channel->whatsappConfig->waba_id && $channel->whatsappConfig->getDecryptedToken(), 422, 'Conectá un canal de WhatsApp activo antes de crear las plantillas.');

        $provisioning = DB::transaction(function () use ($request, $tenantId, $channel) {
            $settings = InvoiceSetting::firstOrCreate(['tenant_id' => $tenantId]);
            $settings->forceFill(['whatsapp_channel_id' => $channel->id])->save();
            $provisioning = InvoiceTemplateProvisioning::firstOrCreate(
                ['tenant_id' => $tenantId, 'whatsapp_config_id' => $channel->whatsapp_config_id, 'version' => 1],
                ['channel_id' => $channel->id, 'state' => 'queued', 'requested_by' => $request->user()->id],
            );
            if ($provisioning->channel_id !== $channel->id) {
                $provisioning->forceFill(['channel_id' => $channel->id])->save();
            }
            if (in_array($provisioning->state, ['partial', 'failed'], true)) {
                $provisioning->forceFill(['state' => 'queued', 'invoice_error' => null, 'reminder_error' => null])->save();
            }
            if (! in_array($provisioning->state, ['ready', 'rejected'], true)) {
                ProvisionInvoiceTemplatesJob::dispatch($provisioning->id)->afterCommit();
            }

            return $provisioning->fresh(['invoiceTemplate', 'reminderTemplate']);
        });

        return response()->json(['data' => $provisioning->toStatusArray()], 202);
    }

    public function updateSettings(Request $request, InvoiceService $service, AutomationRuleService $automationRules)
    {
        $this->authorizeInvoice($request, 'invoices.configure');
        $data = $request->validate(['business_name' => ['nullable', 'string', 'max:200'], 'payment_instructions' => ['nullable', 'string', 'max:3000'], 'whatsapp_channel_id' => ['nullable', 'integer'], 'whatsapp_template_id' => ['nullable', 'integer'], 'reminder_template_id' => ['nullable', 'integer'], 'timezone' => ['required', 'timezone:all'], 'payment_term_days' => ['required', 'integer', 'min:0', 'max:365'], 'send_hour' => ['required', 'integer', 'min:0', 'max:23'], 'reminder_days' => ['required', 'array', 'max:5'], 'reminder_days.*' => ['integer', 'min:0', 'max:365'], 'enabled' => ['boolean']]);
        $tenantId = (int) $request->user()->tenant_id;
        if (! empty($data['whatsapp_channel_id'])) {
            $valid = Channel::where('tenant_id', $tenantId)->whereKey($data['whatsapp_channel_id'])->where('type', ChannelType::WHATSAPP)->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['whatsapp_channel_id' => 'Elegí un canal de WhatsApp del workspace.']);
            }
        }
        if (! empty($data['whatsapp_template_id']) || ! empty($data['reminder_template_id'])) {
            $channel = Channel::with('whatsappConfig')->where('tenant_id', $tenantId)->find($data['whatsapp_channel_id'] ?? null);
            foreach (['whatsapp_template_id' => 'DOCUMENT', 'reminder_template_id' => null] as $key => $format) {
                if (empty($data[$key])) {
                    continue;
                }
                $template = WhatsAppTemplate::where('tenant_id', $tenantId)->where('whatsapp_config_id', $channel?->whatsapp_config_id)->approved()->find($data[$key]);
                if (! $template || $template->category !== TemplateCategory::Utility || ($format && $template->headerMediaFormat() !== $format) || ($key === 'reminder_template_id' && $template->headerMediaFormat() !== null)) {
                    throw ValidationException::withMessages([$key => $format ? 'Elegí una plantilla Utility aprobada con encabezado de documento.' : 'Elegí una plantilla Utility aprobada sin encabezado de archivo para recordatorios.']);
                }
            }
        }
        $settings = InvoiceSetting::firstOrNew(['tenant_id' => $tenantId]);
        $wasEnabled = $settings->enabled;
        $settings->fill($data);
        if (($data['enabled'] ?? false) && (! $settings->business_name || ! $settings->whatsapp_channel_id || ! $settings->whatsapp_template_id || ! $settings->reminder_template_id)) {
            throw ValidationException::withMessages(['enabled' => 'Completá el nombre del negocio, elegí el canal y configurá las plantillas de invoice y recordatorio antes de activar Invoices.']);
        }
        $settings->save();
        if (! $wasEnabled && $settings->enabled) {
            // The previous contact-based collection engine must not double-send.
            BillingConfig::where('tenant_id', $tenantId)->update(['enabled' => false]);
            AutomationRule::where('tenant_id', $tenantId)->where('name', 'like', 'Cobranzas:%')->where('status', 'active')->get()->each(fn ($rule) => $automationRules->pause($rule));
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
