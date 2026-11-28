<?php

namespace App\Services\Observability\Langfuse;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * Descarta el body de las respuestas exitosas del endpoint OTLP de Langfuse.
 *
 * Langfuse responde JSON aunque el request sea protobuf (viola la spec OTLP:
 * https://github.com/langfuse/langfuse/issues/11550), y el exporter de OTel
 * falla al parsearlo como protobuf ("Unexpected wire type") en cada flush,
 * aunque los spans ya se hayan ingerido. Ese body nunca trae partialSuccess,
 * así que no se pierde información. Los errores HTTP siguen propagándose.
 *
 * @implements TransportInterface<string>
 */
final class DiscardResponseBodyTransport implements TransportInterface
{
    public function __construct(private readonly TransportInterface $inner) {}

    public function contentType(): string
    {
        return $this->inner->contentType();
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->inner->send($payload, $cancellation)->map(static fn () => null);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }
}
