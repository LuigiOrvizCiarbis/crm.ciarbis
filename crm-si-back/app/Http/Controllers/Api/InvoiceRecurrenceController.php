<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceRecurrenceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->can('invoices.view'), 403);
        $timezone = InvoiceSetting::where('tenant_id', $request->user()->tenant_id)->value('timezone') ?: 'America/Argentina/Buenos_Aires';
        $today = now($timezone)->toDateString();
        $recurrences = InvoiceRecurrence::with([
            'contact',
            'latestInvoice' => fn ($query) => $query->withSum(
                ['payments as paid_cents' => fn ($payments) => $payments->whereNull('reversed_at')],
                'amount_cents'
            ),
        ])->withCount('invoices')->latest()->paginate(min(100, max(10, (int) $request->input('per_page', 25))));

        $recurrences->getCollection()->each(function (InvoiceRecurrence $recurrence) use ($today): void {
            $invoice = $recurrence->latestInvoice;
            if (! $invoice) return;

            $paid = (int) $invoice->paid_cents;
            $invoice->setAttribute('paid_cents', $paid);
            $invoice->setAttribute('balance_cents', max(0, $invoice->amount_cents - $paid));
            $invoice->setAttribute('collection_status', $invoice->collectionStatus($paid, $today));
        });

        return $recurrences;
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->can('invoices.manage'), 403);
        $data = $request->validate([
            'contact_id' => ['required', 'integer'], 'concept' => ['required', 'string', 'max:500'], 'amount_cents' => ['required', 'integer', 'min:1'],
            'interval_unit' => ['required', 'in:days,weeks,months,years'], 'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'payment_term_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);
        Contact::where('tenant_id', $request->user()->tenant_id)->findOrFail($data['contact_id']);
        return response()->json(['data' => InvoiceRecurrence::create([...$data, 'tenant_id' => $request->user()->tenant_id, 'currency' => 'ARS', 'status' => 'draft'])], 201);
    }

    public function activate(Request $request, InvoiceRecurrence $recurrence)
    {
        abort_unless($request->user()?->can('invoices.manage'), 403);
        abort_unless($recurrence->tenant_id === $request->user()->tenant_id, 404);
        if ($recurrence->status !== 'draft') throw ValidationException::withMessages(['recurrence' => 'Solo se pueden activar recurrencias en borrador.']);
        InvoiceSetting::firstOrCreate(['tenant_id' => $recurrence->tenant_id]);
        $recurrence->update(['status' => 'active', 'activated_at' => now(), 'next_occurrence_on' => $recurrence->starts_on]);
        return ['data' => $recurrence->fresh('contact')];
    }

    public function update(Request $request, InvoiceRecurrence $recurrence)
    {
        abort_unless($request->user()?->can('invoices.manage'), 403);
        abort_unless($recurrence->tenant_id === $request->user()->tenant_id, 404);
        if ($recurrence->status !== 'draft' && $recurrence->status !== 'active') throw ValidationException::withMessages(['recurrence' => 'La recurrencia ya no se puede editar.']);
        $data = $request->validate(['concept' => ['sometimes', 'string', 'max:500'], 'amount_cents' => ['sometimes', 'integer', 'min:1'], 'interval_unit' => ['sometimes', 'in:days,weeks,months,years'], 'interval_count' => ['sometimes', 'integer', 'min:1', 'max:365'], 'ends_on' => ['nullable', 'date'], 'payment_term_days' => ['sometimes', 'integer', 'min:0', 'max:365']]);
        $recurrence->update($data);
        return ['data' => $recurrence->fresh()];
    }

    public function action(Request $request, InvoiceRecurrence $recurrence, string $action)
    {
        abort_unless($request->user()?->can('invoices.manage'), 403);
        abort_unless($recurrence->tenant_id === $request->user()->tenant_id, 404);
        $transitions = ['pause' => ['active', 'paused'], 'resume' => ['paused', 'active'], 'cancel' => [['active', 'paused', 'draft'], 'cancelled']];
        abort_unless(isset($transitions[$action]), 404);
        [$from, $to] = $transitions[$action];
        if (! in_array($recurrence->status, (array) $from, true)) throw ValidationException::withMessages(['recurrence' => 'La recurrencia no permite esta acción.']);
        $recurrence->update(['status' => $to]);
        return ['data' => $recurrence->fresh()];
    }
}
