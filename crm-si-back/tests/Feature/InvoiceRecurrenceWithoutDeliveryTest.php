<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendInvoiceWhatsAppJob;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceRecurrenceWithoutDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurrence_generates_one_draft_per_period_when_whatsapp_delivery_is_disabled(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-06 15:00:00', 'America/Argentina/Buenos_Aires'));

        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Cliente de alquiler', 'source' => 'manual']);
        $recurrence = InvoiceRecurrence::create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'concept' => 'Alquiler',
            'amount_cents' => 500000, 'interval_unit' => 'months', 'interval_count' => 1,
            'starts_on' => '2026-10-06', 'payment_term_days' => 10, 'status' => 'draft',
        ]);

        $this->postJson('/api/invoice-recurrences/'.$recurrence->id.'/activate')->assertOk();
        $this->assertDatabaseHas('invoice_settings', ['tenant_id' => $tenant->id, 'enabled' => false]);

        $this->artisan('invoices:dispatch-due')->assertSuccessful();
        $this->artisan('invoices:dispatch-due')->assertSuccessful();

        $invoice = Invoice::where('invoice_recurrence_id', $recurrence->id)->sole();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('2026-10-06', $invoice->issued_on->toDateString());
        $this->assertSame('2026-10-16', $invoice->due_on->toDateString());
        $this->assertSame('INV-'.str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT), $invoice->number);
        $this->assertSame('2026-11-06', $recurrence->fresh()->next_occurrence_on->toDateString());
        $this->assertDatabaseHas('invoice_events', ['invoice_id' => $invoice->id, 'type' => 'recurrence_generated']);
        Queue::assertNotPushed(SendInvoiceWhatsAppJob::class);
    }
}
