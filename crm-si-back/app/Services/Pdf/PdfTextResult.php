<?php

namespace App\Services\Pdf;

/**
 * Resultado de extraer el texto de un PDF.
 *
 * A diferencia de un simple ?string, distingue cada modo de falla: un contrato
 * escaneado, uno demasiado grande y un pdftotext que crasheó necesitan mensajes
 * distintos en la UI, y "texto vacío" no puede ser el único síntoma de todos.
 *
 * Cuando ok es true el texto puede provenir de un documento parcialmente
 * legible: coverage/pagesWithoutText describen qué se pudo leer, y la UI debe
 * declararlo para no presentar un campo vacío como "no está en el contrato"
 * cuando en realidad está en una página que no se pudo extraer.
 */
class PdfTextResult
{
    public const COVERAGE_FULL = 'full';

    public const COVERAGE_PARTIAL = 'partial';

    /**
     * @param  string  $text  Texto extraído (vacío si ok es false o si vino por visión).
     * @param  string|null  $coverage  full | partial.
     * @param  list<int>  $pagesWithoutText  Páginas (1-indexed) sin texto extraíble.
     * @param  int  $pageCount  Total de páginas del documento.
     * @param  bool  $hasForms  El PDF declara AcroForm/XFA: sus valores NO salen
     *                          en pdftotext, así que puede faltar información.
     * @param  list<array{mime: string, data: string}>  $images  Páginas rasterizadas
     *                                                           en base64, cuando el PDF era un escaneo y hubo que
     *                                                           caer al camino de visión.
     * @param  string|null  $errorCode  no_text_layer | document_too_large |
     *                                  too_many_pages | not_a_pdf | encrypted |
     *                                  extraction_failed | extraction_timeout |
     *                                  vision_unavailable
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $text = '',
        public readonly ?string $coverage = null,
        public readonly array $pagesWithoutText = [],
        public readonly int $pageCount = 0,
        public readonly bool $hasForms = false,
        public readonly array $images = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * @param  list<int>  $pagesWithoutText
     */
    public static function ok(
        string $text,
        int $pageCount,
        array $pagesWithoutText = [],
        bool $hasForms = false,
    ): self {
        return new self(
            ok: true,
            text: $text,
            coverage: $pagesWithoutText === [] ? self::COVERAGE_FULL : self::COVERAGE_PARTIAL,
            pagesWithoutText: $pagesWithoutText,
            pageCount: $pageCount,
            hasForms: $hasForms,
        );
    }

    /**
     * El PDF era un escaneo: no hay texto, van las páginas como imágenes.
     *
     * @param  list<array{mime: string, data: string}>  $images
     */
    public static function scanned(array $images, int $pageCount, bool $hasForms = false): self
    {
        return new self(
            ok: true,
            coverage: self::COVERAGE_FULL,
            pageCount: $pageCount,
            hasForms: $hasForms,
            images: $images,
        );
    }

    public static function failure(string $errorCode, ?string $errorMessage = null): self
    {
        return new self(ok: false, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    public function isPartial(): bool
    {
        return $this->coverage === self::COVERAGE_PARTIAL;
    }

    /** El contenido va como imágenes en vez de texto (PDF escaneado). */
    public function isScanned(): bool
    {
        return $this->images !== [];
    }
}
