<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class VoiceTranscoder
{
    public function transcode(string $input): string
    {
        $base = tempnam(sys_get_temp_dir(), 'voice_');
        if ($base === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal para convertir la nota de voz.');
        }
        unlink($base);
        $output = $base.'.ogg';
        try {
            $process = new Process(['ffmpeg', '-y', '-i', $input, '-vn', '-ac', '1', '-c:a', 'libopus', '-b:a', '32k', '-application', 'voip', '-f', 'ogg', $output]);
            $process->setTimeout(120);
            try {
                $process->mustRun();
            } catch (\Throwable $e) {
                throw new \RuntimeException('No se pudo convertir la nota de voz. Verificá que ffmpeg esté instalado: '.$e->getMessage(), 0, $e);
            }
            if (! is_file($output) || filesize($output) === 0) {
                throw new \RuntimeException('FFmpeg no produjo un archivo OGG válido.');
            }
            try {
                $probe = new Process(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'format=format_name:stream=codec_name,channels', '-of', 'json', $output]);
                $probe->setTimeout(30);
                $probe->mustRun();
                $metadata = json_decode($probe->getOutput(), true);
            } catch (\Throwable $e) {
                throw new \RuntimeException('No se pudo validar la nota de voz convertida. Verificá que ffprobe esté instalado: '.$e->getMessage(), 0, $e);
            }
            $format = $metadata['format']['format_name'] ?? '';
            $stream = $metadata['streams'][0] ?? [];
            if (! str_contains($format, 'ogg') || ($stream['codec_name'] ?? '') !== 'opus' || (int) ($stream['channels'] ?? 0) !== 1) {
                throw new \RuntimeException('La conversión produjo un contenedor/codec de voz inválido (se esperaba OGG/Opus mono).');
            }
            return $output;
        } catch (\Throwable $e) {
            if (is_file($output)) {
                unlink($output);
            }
            throw $e;
        }
    }
}
