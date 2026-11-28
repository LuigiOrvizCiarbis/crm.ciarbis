<?php

namespace App\Services\Observability\Langfuse;

use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;

/**
 * Una observación de Langfuse (un span de OpenTelemetry). Con el tracer
 * apagado el span no graba y todos los setters vuelven sin trabajar.
 */
final class LangfuseObservation
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private const STATUS_MESSAGE_MAX_CHARS = 1000;

    private bool $ended = false;

    /**
     * @param  array<string, string|int|bool|list<string>>  $traceAttributes  Contexto del trace, copiado en cada span hijo.
     */
    public function __construct(
        private readonly SpanInterface $span,
        private readonly TracerInterface $tracer,
        private readonly array $traceAttributes,
        private readonly PayloadSanitizer $sanitizer,
    ) {}

    public static function noop(): self
    {
        return new self(Span::getInvalid(), NoopTracer::getInstance(), [], new PayloadSanitizer(0));
    }

    public function child(string $name, ObservationType $type): self
    {
        $span = $this->tracer->spanBuilder($name)
            ->setParent($this->span->storeInContext(Context::getRoot()))
            ->startSpan();

        return self::start($span, $this->tracer, $this->traceAttributes, $this->sanitizer, $type);
    }

    /** @param  array<string, mixed>  $modelParameters */
    public function generation(string $name, string $model, array $modelParameters = []): self
    {
        $generation = $this->child($name, ObservationType::Generation);
        $generation->set('langfuse.observation.model.name', $model);
        if ($modelParameters !== []) {
            $generation->set('langfuse.observation.model.parameters', $this->json($modelParameters));
        }

        return $generation;
    }

    public function input(mixed $value): self
    {
        if ($this->isRecording()) {
            $this->set('langfuse.observation.input', $this->json($this->sanitizer->sanitize($value)));
        }

        return $this;
    }

    public function output(mixed $value): self
    {
        if ($this->isRecording()) {
            $this->set('langfuse.observation.output', $this->json($this->sanitizer->sanitize($value)));
        }

        return $this;
    }

    /** @param  array<string, mixed>  $values */
    public function metadata(array $values): self
    {
        if (! $this->isRecording()) {
            return $this;
        }

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $sanitized = $this->sanitizer->sanitize($value);
            $this->set('langfuse.observation.metadata.'.$key, is_string($sanitized) ? $sanitized : $this->json($sanitized));
        }

        return $this;
    }

    /** @param  array<string, int>|null  $details */
    public function usage(?array $details): self
    {
        if ($details !== null && $this->isRecording()) {
            $this->set('langfuse.observation.usage_details', $this->json($details));
        }

        return $this;
    }

    public function fail(\Throwable|string $error): self
    {
        if (! $this->isRecording()) {
            return $this;
        }

        $message = $error instanceof \Throwable ? $error::class.': '.$error->getMessage() : $error;
        $message = mb_substr((string) $this->sanitizer->sanitize($message), 0, self::STATUS_MESSAGE_MAX_CHARS);

        $this->set('langfuse.observation.level', 'ERROR');
        $this->set('langfuse.observation.status_message', $message);
        $this->span->setStatus(StatusCode::STATUS_ERROR, $message);

        return $this;
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->span->end();
    }

    public function reference(): ?TraceReference
    {
        $context = $this->span->getContext();

        return $context->isValid() ? new TraceReference($context->getTraceId(), $context->getSpanId()) : null;
    }

    public function isRecording(): bool
    {
        return $this->span->isRecording();
    }

    /** @param  array<string, string|int|bool|list<string>>  $traceAttributes */
    public static function start(
        SpanInterface $span,
        TracerInterface $tracer,
        array $traceAttributes,
        PayloadSanitizer $sanitizer,
        ObservationType $type,
    ): self {
        $observation = new self($span, $tracer, $traceAttributes, $sanitizer);
        if ($span->isRecording()) {
            foreach ($traceAttributes as $key => $value) {
                $span->setAttribute($key, $value);
            }
            $span->setAttribute('langfuse.observation.type', $type->value);
        }

        return $observation;
    }

    /** @param  string|int|bool|list<string>  $value */
    private function set(string $key, string|int|bool|array $value): void
    {
        $this->span->setAttribute($key, $value);
    }

    private function json(mixed $value): string
    {
        // Comparación estricta: "0" es falsy y con ?: el valor 0 se exportaba como null.
        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? 'null' : $json;
    }
}
