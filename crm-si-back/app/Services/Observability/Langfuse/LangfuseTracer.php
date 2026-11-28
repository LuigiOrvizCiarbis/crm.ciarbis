<?php

namespace App\Services\Observability\Langfuse;

use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Punto de entrada a Langfuse: arma los traces y los exporta por OTLP/HTTP
 * (formato v4, basado en observaciones).
 *
 * Los spans se acumulan en memoria y solo se envían en flush(), que corre
 * después de cada job y al terminar cada request: Langfuse nunca suma
 * latencia antes de responderle al cliente. No registra nada global de
 * OpenTelemetry, así no choca con Sentry.
 */
final class LangfuseTracer
{
    private const MAX_QUEUE_SIZE = 2048;

    private const MAX_EXPORT_BATCH_SIZE = 512;

    private const SCHEDULED_DELAY_MILLIS = 5000;

    private const RETRY_DELAY_MILLIS = 100;

    // Sin reintentos: la espera de Retry-After no tiene tope y el flush corre
    // en el worker. Perder un trace es preferible a frenar la cola.
    private const MAX_RETRIES = 0;

    private ?LangfuseObservation $current = null;

    /** @param  array<string, mixed>  $config */
    private function __construct(
        private readonly TracerInterface $tracer,
        private readonly ?TracerProvider $provider,
        private readonly PayloadSanitizer $sanitizer,
        private readonly array $config,
    ) {}

    /** @param  array<string, mixed>  $config  El contenido de config/langfuse.php. */
    public static function fromConfig(array $config): self
    {
        if (! self::isConfigured($config)) {
            return self::noop($config);
        }

        try {
            return self::withExporter(self::otlpExporter($config), $config);
        } catch (\Throwable $e) {
            // Una config inválida apaga el tracing: nunca puede frenar las respuestas de IA.
            Log::warning('LangfuseTracer: config inválida, tracing apagado', ['error' => $e->getMessage()]);

            return self::noop($config);
        }
    }

    /** @param  array<string, mixed>  $config */
    private static function isConfigured(array $config): bool
    {
        return ($config['enabled'] ?? false)
            && filled($config['public_key'] ?? null)
            && filled($config['secret_key'] ?? null);
    }

    /** @param  array<string, mixed>  $config */
    private static function noop(array $config): self
    {
        return new self(NoopTracer::getInstance(), null, new PayloadSanitizer(0), $config);
    }

    /** @param  array<string, mixed>  $config */
    private static function otlpExporter(array $config): SpanExporter
    {
        $host = (string) ($config['host'] ?? '');
        if (! self::isHttpUrl($host)) {
            throw new \InvalidArgumentException("LANGFUSE_HOST no es una URL http(s): '{$host}'");
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            $host.'/api/public/otel/v1/traces',
            'application/x-protobuf',
            [
                'Authorization' => 'Basic '.base64_encode($config['public_key'].':'.$config['secret_key']),
                'x-langfuse-ingestion-version' => '4',
            ],
            null,
            (float) $config['timeout'],
            self::RETRY_DELAY_MILLIS,
            self::MAX_RETRIES,
        );

        return new SpanExporter(new DiscardResponseBodyTransport($transport));
    }

    private static function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /** @param  array<string, mixed>  $config */
    public static function withExporter(SpanExporterInterface $exporter, array $config): self
    {
        // autoFlush=false: nada se exporta a mitad de un flujo, solo en flush().
        $processor = new BatchSpanProcessor(
            $exporter,
            Clock::getDefault(),
            self::MAX_QUEUE_SIZE,
            self::SCHEDULED_DELAY_MILLIS,
            (int) ((float) $config['timeout'] * 1000),
            self::MAX_EXPORT_BATCH_SIZE,
            false,
        );

        $provider = new TracerProvider(
            [$processor],
            null,
            ResourceInfo::create(Attributes::create(['service.name' => 'crm-si-back'])),
        );

        return new self(
            $provider->getTracer('crm-si-back.langfuse'),
            $provider,
            new PayloadSanitizer((int) $config['max_string_chars']),
            $config,
        );
    }

    public function startTrace(string $name, ObservationType $type, TraceContext $context): LangfuseObservation
    {
        $span = $this->tracer->spanBuilder($name)->setParent(Context::getRoot())->startSpan();

        return LangfuseObservation::start($span, $this->tracer, $this->traceAttributes($name, $context), $this->sanitizer, $type);
    }

    /** La observación activa, o una que no graba si no hay ninguna. */
    public function current(): LangfuseObservation
    {
        return $this->current ?? LangfuseObservation::noop();
    }

    /**
     * Ejecuta el callback con la observación como activa: lo que se cree
     * adentro con current() queda como hijo suyo.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function within(LangfuseObservation $observation, \Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $observation;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    /** Exporta lo acumulado. Nunca lanza: Langfuse no puede romper la app. */
    public function flush(): void
    {
        try {
            $this->provider?->forceFlush();
        } catch (\Throwable $e) {
            Log::warning('LangfuseTracer: flush falló', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, string|int|bool|list<string>> */
    private function traceAttributes(string $name, TraceContext $context): array
    {
        $attributes = array_filter([
            'langfuse.trace.name' => $name,
            'langfuse.environment' => $this->config['environment'] ?? null,
            'langfuse.release' => $this->config['release'] ?? null,
            'langfuse.session.id' => $context->sessionId,
            'langfuse.user.id' => $context->userId,
            'langfuse.trace.tags' => array_values(array_filter($context->tags, fn (string $tag) => $tag !== '')),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        foreach ($context->metadata as $key => $value) {
            if ($value !== null) {
                $attributes['langfuse.trace.metadata.'.$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $attributes;
    }
}
