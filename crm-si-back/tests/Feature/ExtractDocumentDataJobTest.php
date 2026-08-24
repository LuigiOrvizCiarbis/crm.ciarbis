<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Enums\ExtractionStatus;
use App\Jobs\ExtractDocumentDataJob;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\DocumentExtraction;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Services\DocumentExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractDocumentDataJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_the_result_and_the_extracted_text_on_success(): void
    {
        $extraction = $this->makeExtraction();

        $this->mockService([
            'ok' => true,
            'data' => ['monto' => 150000],
            'fields' => ['monto' => 'number'],
            'text' => '[Página 1] CONTRATO',
            'coverage' => 'full',
            'pagesWithoutText' => [],
            'inputTokens' => 8000,
            'outputTokens' => 50,
        ]);

        (new ExtractDocumentDataJob($extraction->tenant_id, $extraction->id))
            ->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame(ExtractionStatus::Completed, $extraction->status);
        $this->assertSame(['monto' => 150000], $extraction->result);
        $this->assertSame(['monto' => 'number'], $extraction->fields_snapshot);
        $this->assertSame('full', $extraction->text_coverage);
        $this->assertSame(8000, $extraction->input_tokens);
    }

    #[Test]
    public function it_records_partial_coverage_so_the_ui_can_declare_it(): void
    {
        $extraction = $this->makeExtraction();

        $this->mockService([
            'ok' => true,
            'data' => ['monto' => null],
            'fields' => ['monto' => 'number'],
            'text' => '[Página 1] CARATULA',
            'coverage' => 'partial',
            'pagesWithoutText' => [2, 3],
            'inputTokens' => 100,
            'outputTokens' => 5,
        ]);

        (new ExtractDocumentDataJob($extraction->tenant_id, $extraction->id))
            ->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        // Un null con cobertura parcial no significa "no está en el contrato":
        // puede estar en una página que no se pudo leer.
        $this->assertSame('partial', $extraction->text_coverage);
        $this->assertSame([2, 3], $extraction->pages_without_text);
    }

    #[Test]
    public function it_persists_a_typed_error_instead_of_leaving_it_processing(): void
    {
        $extraction = $this->makeExtraction();

        $this->mockService([
            'ok' => false,
            'errorCode' => 'no_text_layer',
            'errorMessage' => 'El PDF parece escaneado.',
            'inputTokens' => null,
            'outputTokens' => null,
        ]);

        (new ExtractDocumentDataJob($extraction->tenant_id, $extraction->id))
            ->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame(ExtractionStatus::Failed, $extraction->status);
        $this->assertSame('no_text_layer', $extraction->error_code);
    }

    #[Test]
    public function a_second_job_on_the_same_extraction_does_not_call_the_provider(): void
    {
        // Redelivery por retry_after: sin el claim compare-and-set el documento
        // se mandaría al proveedor dos veces y se pagaría dos veces.
        $extraction = $this->makeExtraction();

        $calls = 0;
        $this->mock(DocumentExtractionService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('run')->andReturnUsing(function () use (&$calls) {
                $calls++;

                return [
                    'ok' => true,
                    'data' => [],
                    'fields' => [],
                    'text' => 'x',
                    'coverage' => 'full',
                    'pagesWithoutText' => [],
                    'inputTokens' => 1,
                    'outputTokens' => 1,
                ];
            });
        });

        $job = new ExtractDocumentDataJob($extraction->tenant_id, $extraction->id);
        $job->handle(app(DocumentExtractionService::class));
        $job->handle(app(DocumentExtractionService::class));

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function a_late_failure_does_not_overwrite_a_completed_extraction(): void
    {
        $extraction = $this->makeExtraction();
        $extraction->update([
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000],
        ]);

        (new ExtractDocumentDataJob($extraction->tenant_id, $extraction->id))
            ->failed(new \RuntimeException('timeout tardío'));

        $extraction->refresh();
        $this->assertSame(ExtractionStatus::Completed, $extraction->status);
        $this->assertNull($extraction->error_code);
    }

    #[Test]
    public function it_ignores_an_extraction_from_another_tenant(): void
    {
        // TenantScope es no-op sin usuario autenticado: si el job no filtrara
        // por tenant, procesaría documentos ajenos.
        $extraction = $this->makeExtraction();
        $otherTenant = $this->createTenantWithRoles('Otro');

        $calls = 0;
        $this->mock(DocumentExtractionService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('run')->andReturnUsing(function () use (&$calls) {
                $calls++;

                return ['ok' => false, 'errorCode' => 'x', 'errorMessage' => null];
            });
        });

        (new ExtractDocumentDataJob($otherTenant->id, $extraction->id))
            ->handle(app(DocumentExtractionService::class));

        $this->assertSame(0, $calls);
        $extraction->refresh();
        $this->assertSame(ExtractionStatus::Queued, $extraction->status);
    }

    #[Test]
    public function the_watchdog_recovers_an_extraction_whose_worker_died(): void
    {
        // Un OOM mata el proceso sin pasar por failed(): la fila queda en
        // processing y el claim impide que otro job la retome.
        $extraction = $this->makeExtraction();
        $extraction->update([
            'status' => ExtractionStatus::Processing,
            'processing_started_at' => now()->subHour(),
        ]);

        $this->artisan('extractions:reclaim')->assertSuccessful();

        $extraction->refresh();
        $this->assertSame(ExtractionStatus::Failed, $extraction->status);
        $this->assertSame('stalled', $extraction->error_code);
    }

    #[Test]
    public function the_watchdog_leaves_a_running_extraction_alone(): void
    {
        $extraction = $this->makeExtraction();
        $extraction->update([
            'status' => ExtractionStatus::Processing,
            'processing_started_at' => now(),
        ]);

        $this->artisan('extractions:reclaim')->assertSuccessful();

        $extraction->refresh();
        $this->assertSame(ExtractionStatus::Processing, $extraction->status);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function mockService(array $result): void
    {
        $this->mock(DocumentExtractionService::class, function ($mock) use ($result) {
            $mock->shouldReceive('run')->andReturn($result);
        });
    }

    private function makeExtraction(): DocumentExtraction
    {
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'monto',
            'label' => 'Monto',
            'type' => ContactFieldType::Number,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inquilino',
            'source' => 'manual',
        ]);

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'name' => 'contrato.pdf',
            'path' => 'media-assets/'.$tenant->id.'/contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        return DocumentExtraction::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'media_asset_id' => $asset->id,
            'status' => ExtractionStatus::Queued,
            'contact_lock_version' => 0,
        ]);
    }
}
