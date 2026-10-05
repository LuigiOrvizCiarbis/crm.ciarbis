<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoicePdfRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_pdf_is_regenerated_from_the_invoice_snapshots(): void
    {
        Storage::fake('local');
        $tenant = Tenant::create(['name' => 'Comercio de prueba']);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente de prueba',
            'phone' => '+5492235550101',
            'email' => 'cliente@example.test',
            'source' => 'manual',
        ]);
        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'number' => 'INV-RECOVERY-1',
            'concept' => 'Servicio mensual',
            'amount_cents' => 5000000,
            'status' => 'issued',
            'issued_on' => '2026-10-05',
            'due_on' => '2026-10-15',
            'issuer_snapshot' => ['name' => 'Negocio original', 'instructions' => 'Transferencia bancaria'],
            'contact_snapshot' => ['name' => 'Cliente original', 'phone' => '+5492235550102', 'email' => 'original@example.test'],
        ]);

        $path = app(InvoiceService::class)->ensurePdfExists($invoice);

        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, $invoice->fresh()->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($path));
    }
}
