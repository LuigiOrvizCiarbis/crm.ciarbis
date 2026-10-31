<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Jobs\SendInvoiceWhatsAppJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\InvoiceSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceIssueOnCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_issued_invoice_sets_due_date_pdf_and_queues_delivery(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'America/Argentina/Buenos_Aires'));

        $tenant = Tenant::create(['name' => 'Comercio de prueba']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente de prueba',
            'phone' => '+5492235550101',
            'source' => 'manual',
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp de prueba',
            'status' => 'active',
        ]);
        $config = WhatsAppConfig::create([
            'channel_id' => $channel->id,
            'phone_number_id' => 'phone-test',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('token-test'),
        ]);
        $template = WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'template-test',
            'name' => 'invoice_test',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'HEADER', 'format' => 'DOCUMENT'], ['type' => 'BODY', 'text' => 'Vence {{vencimiento}}']],
        ]);
        InvoiceSetting::create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'whatsapp_channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'business_name' => 'Comercio de prueba',
            'payment_term_days' => 10,
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $invoice = app(InvoiceService::class)->create($tenant->id, $user, [
            'contact_id' => $contact->id,
            'concept' => 'Cobro de prueba',
            'amount_cents' => 50000,
            'status' => 'issued',
        ]);

        $this->assertSame('issued', $invoice->status);
        $this->assertSame('2026-10-05', $invoice->issued_on->toDateString());
        $this->assertSame('2026-10-15', $invoice->due_on->toDateString());
        $this->assertSame('pending', $invoice->delivery_status);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        $this->assertDatabaseHas('invoice_events', ['invoice_id' => $invoice->id, 'type' => 'issued']);
        Queue::assertPushed(SendInvoiceWhatsAppJob::class, fn (SendInvoiceWhatsAppJob $job) => $job->invoiceId === $invoice->id && $job->tenantId === $tenant->id);
    }

    public function test_issuing_for_a_contact_without_phone_is_rejected_before_creating_an_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Comercio de prueba']);
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Sin teléfono', 'source' => 'manual']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        try {
            app(InvoiceService::class)->create($tenant->id, $user, [
                'contact_id' => $contact->id,
                'concept' => 'Cobro de prueba',
                'amount_cents' => 50000,
                'status' => 'issued',
            ]);
            $this->fail('A contact without a phone must not be issued an invoice for WhatsApp delivery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contact_id', $exception->errors());
        }

        $this->assertDatabaseCount('invoices', 0);
    }

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
