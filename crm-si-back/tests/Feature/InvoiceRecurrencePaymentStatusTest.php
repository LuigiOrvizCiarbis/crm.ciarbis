<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceRecurrence;
use App\Models\Tenant;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceRecurrencePaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurrence_exposes_the_latest_period_payment_status_and_period_filter(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Cliente', 'source' => 'manual']);
        $recurrence = InvoiceRecurrence::create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'concept' => 'Alquiler',
            'amount_cents' => 10000, 'interval_unit' => 'months', 'interval_count' => 1,
            'starts_on' => '2026-09-01', 'payment_term_days' => 10, 'status' => 'active',
        ]);
        $older = Invoice::create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'invoice_recurrence_id' => $recurrence->id,
            'number' => 'INV-OLD', 'concept' => 'Alquiler', 'amount_cents' => 10000,
            'issued_on' => '2026-09-01', 'status' => 'issued',
        ]);
        $latest = Invoice::create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'invoice_recurrence_id' => $recurrence->id,
            'number' => 'INV-NEW', 'concept' => 'Alquiler', 'amount_cents' => 10000,
            'issued_on' => '2026-10-01', 'status' => 'issued',
        ]);
        InvoicePayment::create([
            'tenant_id' => $tenant->id, 'invoice_id' => $latest->id,
            'amount_cents' => 3000, 'paid_on' => '2026-10-02',
        ]);
        Invoice::create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id,
            'number' => 'INV-OTHER', 'concept' => 'Único', 'amount_cents' => 10000, 'status' => 'draft',
        ]);

        $this->getJson('/api/invoice-recurrences')
            ->assertOk()
            ->assertJsonPath('data.0.latest_invoice.id', $latest->id)
            ->assertJsonPath('data.0.latest_invoice.paid_cents', 3000)
            ->assertJsonPath('data.0.latest_invoice.balance_cents', 7000)
            ->assertJsonPath('data.0.latest_invoice.collection_status', 'partial')
            ->assertJsonPath('data.0.invoices_count', 2);

        $response = $this->getJson('/api/invoices?invoice_recurrence_id='.$recurrence->id);
        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$older->id, $latest->id], collect($response->json('data'))->pluck('id')->all());

        $otherTenant = Tenant::create(['name' => 'Otro comercio']);
        $otherContact = Contact::create(['tenant_id' => $otherTenant->id, 'name' => 'Otro cliente', 'source' => 'manual']);
        $otherRecurrence = InvoiceRecurrence::create([
            'tenant_id' => $otherTenant->id, 'contact_id' => $otherContact->id, 'concept' => 'Otro alquiler',
            'amount_cents' => 10000, 'interval_unit' => 'months', 'interval_count' => 1,
            'starts_on' => '2026-09-01', 'payment_term_days' => 10, 'status' => 'active',
        ]);
        Invoice::create([
            'tenant_id' => $otherTenant->id, 'contact_id' => $otherContact->id,
            'invoice_recurrence_id' => $otherRecurrence->id, 'number' => 'INV-FOREIGN',
            'concept' => 'Otro alquiler', 'amount_cents' => 10000, 'issued_on' => '2026-09-01', 'status' => 'issued',
        ]);

        $this->getJson('/api/invoices?invoice_recurrence_id='.$otherRecurrence->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/invoice-recurrences')->assertOk()->assertJsonCount(1, 'data');
    }
}
