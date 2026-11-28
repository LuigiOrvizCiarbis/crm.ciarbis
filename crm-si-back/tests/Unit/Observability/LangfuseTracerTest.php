<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\Langfuse\LangfuseTracer;
use App\Services\Observability\Langfuse\ObservationType;
use App\Services\Observability\Langfuse\TraceContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LangfuseTracerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function invalidHosts(): array
    {
        return [
            'vacío' => [''],
            'sin esquema' => ['cloud.langfuse.com'],
            'esquema no http' => ['ftp://cloud.langfuse.com'],
        ];
    }

    #[DataProvider('invalidHosts')]
    public function test_invalid_host_falls_back_to_noop_tracer(string $host): void
    {
        $tracer = LangfuseTracer::fromConfig($this->config($host));

        $trace = $tracer->startTrace('ai-auto-reply', ObservationType::Agent, new TraceContext('session-1'));

        $this->assertFalse($trace->isRecording());
        $tracer->flush();
    }

    public function test_valid_host_builds_recording_tracer(): void
    {
        $tracer = LangfuseTracer::fromConfig($this->config('https://cloud.langfuse.com'));

        $trace = $tracer->startTrace('ai-auto-reply', ObservationType::Agent, new TraceContext('session-1'));

        $this->assertTrue($trace->isRecording());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function inactiveConfigs(): array
    {
        return [
            'apagado' => [['enabled' => false]],
            'sin public key' => [['public_key' => null]],
            'sin secret key' => [['secret_key' => '']],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('inactiveConfigs')]
    public function test_disabled_or_keyless_config_builds_noop_tracer(array $overrides): void
    {
        $tracer = LangfuseTracer::fromConfig(array_merge($this->config('https://cloud.langfuse.com'), $overrides));

        $trace = $tracer->startTrace('ai-auto-reply', ObservationType::Agent, new TraceContext('session-1'));

        $this->assertFalse($trace->isRecording());
    }

    /** @return array<string, mixed> */
    private function config(string $host): array
    {
        return [
            'enabled' => true,
            'host' => $host,
            'public_key' => 'pk-lf-test',
            'secret_key' => 'sk-lf-test',
            'environment' => 'testing',
            'release' => null,
            'timeout' => 3.0,
            'max_string_chars' => 1000,
        ];
    }
}
