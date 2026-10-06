<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceIssueOnCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_issued_invoice_runs_issue_validation(): void
    {
        $tenant = Tenant::create(['name' => 'Comercio de prueba']);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente de prueba',
            'phone' => '+5492235550101',
            'source' => 'manual',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        try {
            app(InvoiceService::class)->create($tenant->id, $user, [
                'contact_id' => $contact->id,
                'concept' => 'Cobro de prueba',
                'amount_cents' => 50000,
                'status' => 'issued',
            ]);
            $this->fail('An issued invoice without configured settings must not bypass issue().');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $this->assertDatabaseCount('invoices', 0);
    }
}
