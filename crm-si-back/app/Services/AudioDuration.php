<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Duración real de un audio, medida con ffprobe.
 *
 * Se recorren los paquetes en vez de leer la duración del contenedor: las
 * grabaciones de MediaRecorder (webm de Chrome) no la traen en la cabecera.
 */
class AudioDuration
{
    private const TIMEOUT_SECONDS = 30;

    /** Segundos de audio, o null si ffprobe no pudo leer el archivo. */
    public function seconds(string $path): ?float
    {
        try {
            $process = new Process([
                'ffprobe', '-v', 'error', '-select_streams', 'a:0',
                '-show_entries', 'packet=pts_time', '-of', 'csv=p=0', $path,
            ]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->mustRun();
        } catch (Throwable $e) {
            Log::warning('AudioDuration: ffprobe falló', ['error' => $e->getMessage()]);

            return null;
        }

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $last = rtrim((string) end($lines), ',');

        return is_numeric($last) ? (float) $last : null;
    }
}
