<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\Langfuse\DiscardResponseBodyTransport;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class DiscardResponseBodyTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // El exporter loguea los fallos de export por error_log: se silencian.
        LoggerHolder::set(new NullLogger);
    }

    protected function tearDown(): void
    {
        LoggerHolder::unset();
        parent::tearDown();
    }

    public function test_json_success_body_from_langfuse_counts_as_exported(): void
    {
        // Lo que responde Langfuse a un request protobuf: el job de ingesta en JSON.
        $exporter = new SpanExporter(new DiscardResponseBodyTransport($this->transport(new CompletedFuture('{"name":"otel-ingestion"}'))));

        $this->assertTrue($exporter->export([])->await());
    }

    public function test_http_errors_still_fail_the_export(): void
    {
        $exporter = new SpanExporter(new DiscardResponseBodyTransport($this->transport(new ErrorFuture(new RuntimeException('401 Unauthorized')))));

        $this->assertFalse($exporter->export([])->await());
    }

    private function transport(FutureInterface $response): TransportInterface
    {
        return new class($response) implements TransportInterface
        {
            public function __construct(private FutureInterface $response) {}

            public function contentType(): string
            {
                return 'application/x-protobuf';
            }

            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                return $this->response;
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
    }
}
