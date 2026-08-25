<?php

namespace App\Services\Pdf;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Extrae el texto de un PDF con pdftotext (poppler-utils).
 *
 * Se usa el modo normal, SIN -layout: el manual de pdftotext documenta que el
 * modo normal resuelve el orden de lectura y deshace el guionado de fin de
 * línea, mientras que -layout conserva la disposición física pero mantiene las
 * palabras cortadas. Para mandarle texto a un modelo, el orden de lectura vale
 * más que las columnas.
 *
 * Todo el trabajo pesado lo hace un binario nativo sobre un archivo que sube un
 * usuario, así que corre acotado: timeout, tope de páginas y ulimit de memoria.
 * El worker de producción tiene 256 MB y un OOM lo mataría entero.
 */
class PdfTextExtractor
{
    public function extract(string $absolutePath): PdfTextResult
    {
        if (! is_file($absolutePath)) {
            return PdfTextResult::failure('file_missing', 'El archivo no existe.');
        }

        if (! $this->hasPdfSignature($absolutePath)) {
            return PdfTextResult::failure('not_a_pdf', 'El archivo no es un PDF válido.');
        }

        $maxBytes = (int) config('services.ai.extraction.max_file_bytes');
        $size = filesize($absolutePath);
        if ($size === false || $size > $maxBytes) {
            return PdfTextResult::failure(
                'document_too_large',
                'El PDF supera el tamaño máximo para extracción.',
            );
        }

        $info = $this->info($absolutePath);
        if ($info === null) {
            return PdfTextResult::failure('extraction_failed', 'No se pudo leer la estructura del PDF.');
        }

        if ($info['encrypted']) {
            return PdfTextResult::failure('encrypted', 'El PDF está protegido con contraseña.');
        }

        $maxPages = (int) config('services.ai.extraction.max_pages');
        if ($info['pages'] < 1) {
            return PdfTextResult::failure('extraction_failed', 'El PDF no declara páginas.');
        }
        if ($info['pages'] > $maxPages) {
            return PdfTextResult::failure(
                'too_many_pages',
                "El PDF tiene {$info['pages']} páginas y el máximo es {$maxPages}.",
            );
        }

        return $this->extractPages($absolutePath, $info['pages'], $info['hasForms']);
    }

    /**
     * Extrae página por página para saber cuáles quedaron sin texto. Un contrato
     * híbrido (carátula digital + cláusulas escaneadas) supera cualquier umbral
     * global de caracteres y aun así perdió lo que importa, así que la cobertura
     * se mide por página y no sobre el total.
     */
    private function extractPages(string $path, int $pageCount, bool $hasForms): PdfTextResult
    {
        $minPageChars = (int) config('services.ai.extraction.min_page_chars');
        $maxChars = (int) config('services.ai.extraction.max_chars');

        $pages = [];
        $pagesWithoutText = [];
        $total = 0;

        for ($page = 1; $page <= $pageCount; $page++) {
            // -f y -l juntos: -l solo fija la última página, no aísla un rango.
            $text = $this->runPdftotext($path, ['-f', (string) $page, '-l', (string) $page]);

            if ($text === null) {
                return PdfTextResult::failure(
                    'extraction_failed',
                    "No se pudo extraer el texto de la página {$page}.",
                );
            }

            if ($text === self::TIMED_OUT) {
                return PdfTextResult::failure(
                    'extraction_timeout',
                    'La extracción del PDF tardó demasiado.',
                );
            }

            $trimmed = trim($text);

            // Una página en blanco intencional y una escaneada son
            // indistinguibles desde pdftotext. Se reportan igual, como "sin
            // texto extraíble", sin afirmar cuál de las dos es.
            if (mb_strlen($trimmed) < $minPageChars) {
                $pagesWithoutText[] = $page;

                continue;
            }

            $total += mb_strlen($trimmed);
            if ($total > $maxChars) {
                return PdfTextResult::failure(
                    'document_too_large',
                    'El texto del PDF supera el máximo admitido para extracción.',
                );
            }

            $pages[] = "[Página {$page}]\n".$trimmed;
        }

        // Ninguna página tenía texto: es un escaneo. El contrato firmado casi
        // siempre lo es, así que en vez de rendirse se rasterizan las páginas y
        // se mandan como imágenes a un modelo con visión.
        if ($pages === []) {
            return $this->rasterize($path, $pageCount, $hasForms);
        }

        return PdfTextResult::ok(
            text: implode("\n\n", $pages),
            pageCount: $pageCount,
            pagesWithoutText: $pagesWithoutText,
            hasForms: $hasForms,
        );
    }

    /**
     * Convierte cada página en una imagen JPEG para mandarla a un modelo con
     * visión. Es el camino caro: cuesta bastante más que el texto y ata la
     * extracción a proveedores con visión, así que sólo se usa cuando pdftotext
     * no encontró nada.
     */
    private function rasterize(string $path, int $pageCount, bool $hasForms): PdfTextResult
    {
        if (! config('services.ai.extraction.vision.enabled', true)) {
            return PdfTextResult::failure(
                'no_text_layer',
                'El PDF no tiene texto seleccionable (parece escaneado).',
            );
        }

        $maxPages = (int) config('services.ai.extraction.vision.max_pages', 20);
        if ($pageCount > $maxPages) {
            return PdfTextResult::failure(
                'too_many_pages',
                "El documento escaneado tiene {$pageCount} páginas y el máximo para este modo es {$maxPages}.",
            );
        }

        $dpi = (int) config('services.ai.extraction.vision.dpi', 120);
        $maxTotal = (int) config('services.ai.extraction.vision.max_total_bytes', 20971520);
        $timeout = (int) config('services.ai.extraction.pdftotext_timeout', 30);
        $memoryKb = (int) config('services.ai.extraction.pdftotext_memory_kb', 131072);

        $dir = sys_get_temp_dir().'/pdfvision_'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            return PdfTextResult::failure('extraction_failed', 'No se pudo preparar la conversión del PDF.');
        }

        try {
            // Rasterizar es más pesado que extraer texto, así que el timeout se
            // escala con la cantidad de páginas.
            $command = sprintf(
                'ulimit -v %d && pdftoppm -jpeg -r %d -f 1 -l %d %s %s',
                $memoryKb,
                $dpi,
                $pageCount,
                escapeshellarg($path),
                escapeshellarg($dir.'/pg'),
            );

            $process = Process::fromShellCommandline($command);
            $process->setTimeout($timeout * max(1, (int) ceil($pageCount / 2)));

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return PdfTextResult::failure(
                    'extraction_timeout',
                    'La conversión del PDF escaneado tardó demasiado.',
                );
            }

            if (! $process->isSuccessful()) {
                return PdfTextResult::failure(
                    'extraction_failed',
                    'No se pudieron convertir las páginas del PDF escaneado.',
                );
            }

            $files = glob($dir.'/pg*.jpg') ?: [];
            sort($files, SORT_NATURAL);

            if ($files === []) {
                return PdfTextResult::failure(
                    'no_text_layer',
                    'El PDF no tiene texto seleccionable y no se pudo convertir a imágenes.',
                );
            }

            $images = [];
            $total = 0;

            foreach ($files as $file) {
                $binary = file_get_contents($file);
                if ($binary === false) {
                    continue;
                }

                $encoded = base64_encode($binary);
                $total += strlen($encoded);

                if ($total > $maxTotal) {
                    return PdfTextResult::failure(
                        'document_too_large',
                        'El PDF escaneado es demasiado grande para procesarlo.',
                    );
                }

                $images[] = ['mime' => 'image/jpeg', 'data' => $encoded];
            }

            return PdfTextResult::scanned($images, $pageCount, $hasForms);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }
    }

    private const TIMED_OUT = "\x00__TIMED_OUT__\x00";

    /**
     * Corre pdftotext con un tope de memoria virtual. Sin ulimit, un PDF
     * patológico puede consumir toda la memoria del contenedor y provocar un OOM
     * que mata el worker (no solo este job).
     *
     * @param  list<string>  $args
     * @return string|null El texto, self::TIMED_OUT, o null ante fallo.
     */
    private function runPdftotext(string $path, array $args): ?string
    {
        $memoryKb = (int) config('services.ai.extraction.pdftotext_memory_kb');
        $timeout = (int) config('services.ai.extraction.pdftotext_timeout');

        // ulimit necesita un shell, así que acá sí se arma una línea de comando.
        // Todo argumento variable pasa por escapeshellarg.
        $command = sprintf(
            'ulimit -v %d && pdftotext -enc UTF-8 %s %s -',
            $memoryKb,
            implode(' ', array_map('escapeshellarg', $args)),
            escapeshellarg($path),
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return self::TIMED_OUT;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * Metadata del PDF vía pdfinfo. Los valores de un AcroForm/XFA no salen en
     * pdftotext, así que hay que declarar su presencia: el usuario tiene que
     * saber que puede faltar información aunque el texto se haya extraído.
     *
     * @return array{pages: int, encrypted: bool, hasForms: bool}|null
     */
    private function info(string $path): ?array
    {
        $timeout = (int) config('services.ai.extraction.pdftotext_timeout');

        $process = new Process(['pdfinfo', $path]);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $pages = 0;
        $encrypted = false;
        $hasForms = false;

        foreach (explode("\n", $process->getOutput()) as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);

            match ($key) {
                'Pages' => $pages = (int) $value,
                'Encrypted' => $encrypted = ! str_starts_with(strtolower($value), 'no'),
                'Form' => $hasForms = $value !== '' && strtolower($value) !== 'none',
                default => null,
            };
        }

        return ['pages' => $pages, 'encrypted' => $encrypted, 'hasForms' => $hasForms];
    }

    private function hasPdfSignature(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 5) === '%PDF-';
        } finally {
            fclose($handle);
        }
    }
}
