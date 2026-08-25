<?php

namespace Tests\Unit;

use App\Services\Pdf\PdfTextExtractor;
use App\Services\Pdf\PdfTextResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfTextExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_extracts_text_page_by_page(): void
    {
        $path = $this->makePdf([
            'CONTRATO DE LOCACION',
            'Deposito en garantia',
        ]);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertTrue($result->ok);
        $this->assertSame(PdfTextResult::COVERAGE_FULL, $result->coverage);
        $this->assertSame(2, $result->pageCount);
        $this->assertSame([], $result->pagesWithoutText);
        $this->assertStringContainsString('CONTRATO DE LOCACION', $result->text);
        $this->assertStringContainsString('Deposito en garantia', $result->text);
        // El marcador de página permite que la UI ubique de dónde salió un dato.
        $this->assertStringContainsString('[Página 1]', $result->text);
        $this->assertStringContainsString('[Página 2]', $result->text);
    }

    #[Test]
    public function it_reports_partial_coverage_and_names_the_unreadable_pages(): void
    {
        // Contrato híbrido: carátula digital, cláusulas escaneadas, firma digital.
        // Un umbral global de caracteres lo daría por bueno y luego reportaría
        // como ausentes los datos de la página que no se pudo leer.
        $path = $this->makePdf([
            'CARATULA DEL CONTRATO',
            ' ',
            'Firma del garante',
        ]);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->isPartial());
        $this->assertSame([2], $result->pagesWithoutText);
        $this->assertSame(3, $result->pageCount);
        $this->assertStringNotContainsString('[Página 2]', $result->text);
    }

    #[Test]
    public function it_rasterizes_a_scanned_pdf_instead_of_giving_up(): void
    {
        // Un contrato firmado casi siempre es un escaneo, así que rendirse ahí
        // dejaría la feature inservible para su caso principal: se convierten
        // las páginas a imágenes para un modelo con visión.
        $path = $this->makePdf([' ', ' ']);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->isScanned());
        $this->assertCount(2, $result->images);
        $this->assertSame('image/jpeg', $result->images[0]['mime']);
        $this->assertNotSame('', $result->images[0]['data']);
        $this->assertSame('', $result->text);
    }

    #[Test]
    public function it_fails_when_the_pdf_has_no_text_layer_and_vision_is_disabled(): void
    {
        config(['services.ai.extraction.vision.enabled' => false]);
        $path = $this->makePdf([' ']);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertFalse($result->ok);
        $this->assertSame('no_text_layer', $result->errorCode);
    }

    #[Test]
    public function it_refuses_to_rasterize_a_scan_beyond_the_page_limit(): void
    {
        // Cada página es una imagen y el costo escala lineal.
        config(['services.ai.extraction.vision.max_pages' => 1]);
        $path = $this->makePdf([' ', ' ']);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertFalse($result->ok);
        $this->assertSame('too_many_pages', $result->errorCode);
    }

    #[Test]
    public function it_rejects_a_file_that_is_not_a_pdf_despite_its_name(): void
    {
        // El mime que valida Laravel en el upload puede venir del cliente; la
        // firma del archivo no.
        $path = tempnam(sys_get_temp_dir(), 'fake_').'.pdf';
        file_put_contents($path, 'esto no es un PDF');
        $this->tempFiles[] = $path;

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertFalse($result->ok);
        $this->assertSame('not_a_pdf', $result->errorCode);
    }

    #[Test]
    public function it_rejects_a_missing_file(): void
    {
        $result = app(PdfTextExtractor::class)->extract('/tmp/no-existe-'.uniqid().'.pdf');

        $this->assertFalse($result->ok);
        $this->assertSame('file_missing', $result->errorCode);
    }

    #[Test]
    public function it_rejects_documents_over_the_page_limit(): void
    {
        config(['services.ai.extraction.max_pages' => 1]);
        $path = $this->makePdf(['uno', 'dos']);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertFalse($result->ok);
        $this->assertSame('too_many_pages', $result->errorCode);
    }

    #[Test]
    public function it_rejects_documents_over_the_byte_limit(): void
    {
        config(['services.ai.extraction.max_file_bytes' => 10]);
        $path = $this->makePdf(['contenido que supera diez bytes']);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertFalse($result->ok);
        $this->assertSame('document_too_large', $result->errorCode);
    }

    #[Test]
    public function it_fails_instead_of_truncating_when_text_exceeds_the_limit(): void
    {
        // Truncar haría que el modelo reporte como ausente lo que quedó afuera:
        // un null indistinguible de "el dato no está en el contrato".
        config(['services.ai.extraction.max_chars' => 5]);
        $path = $this->makePdf(['Un texto bastante mas largo que cinco caracteres']);

        $result = app(PdfTextExtractor::class)->extract($path);

        $this->assertFalse($result->ok);
        $this->assertSame('document_too_large', $result->errorCode);
    }

    /**
     * Arma un PDF mínimo con una página por cada texto recibido. Se escribe a
     * mano para no depender de una librería de generación en los tests.
     *
     * @param  list<string>  $pageTexts
     */
    private function makePdf(array $pageTexts): string
    {
        $pageCount = count($pageTexts);
        $fontObj = 3 + ($pageCount * 2);

        $objects = $this->pdfObject(1, '<< /Type /Catalog /Pages 2 0 R >>');

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + $i).' 0 R';
        }
        $objects .= $this->pdfObject(2, '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>');

        for ($i = 0; $i < $pageCount; $i++) {
            $contentObj = 3 + $pageCount + $i;
            $objects .= $this->pdfObject(
                3 + $i,
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.$contentObj.' 0 R '.
                '/Resources << /Font << /F1 '.$fontObj.' 0 R >> >> >>',
            );
        }

        for ($i = 0; $i < $pageCount; $i++) {
            $text = $pageTexts[$i];
            $stream = trim($text) === ''
                ? ' '
                : 'BT /F1 12 Tf 50 700 Td ('.$text.') Tj ET';
            $objects .= $this->pdfObject(
                3 + $pageCount + $i,
                '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            );
        }

        $objects .= $this->pdfObject($fontObj, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');

        $pdf = "%PDF-1.4\n".$objects."trailer\n<< /Size ".($fontObj + 1)." /Root 1 0 R >>\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'pdftest_').'.pdf';
        file_put_contents($path, $pdf);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function pdfObject(int $number, string $body): string
    {
        return $number." 0 obj\n".$body."\nendobj\n";
    }
}
