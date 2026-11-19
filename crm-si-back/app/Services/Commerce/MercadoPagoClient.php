<?php

namespace App\Services\Commerce;

use App\Models\MercadoPagoConfig;
use App\Models\WhatsAppCheckout;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de Mercado Pago (Checkout Pro) con la credencial del tenant.
 */
class MercadoPagoClient
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private const TIMEOUT = 8;

    private const INVALID_TOKEN = 'La credencial de Mercado Pago no es válida.';

    private const GENERIC_ERROR = 'No pudimos generar el link de pago. Probá de nuevo.';

    /**
     * @return array{ok: bool, nickname: ?string}
     *
     * @throws CheckoutException si MP no responde o responde con un error distinto de 401.
     */
    public function verify(string $token): array
    {
        $response = $this->send(fn () => $this->http($token)->get('/users/me'), allowUnauthorized: true);

        if ($response->status() === 401) {
            return ['ok' => false, 'nickname' => null];
        }

        return ['ok' => true, 'nickname' => $response->json('nickname')];
    }

    /**
     * @return array{id: string, init_point: string}
     *
     * @throws CheckoutException
     */
    public function createPreference(MercadoPagoConfig $config, WhatsAppCheckout $checkout, string $title, string $returnUrl): array
    {
        $quote = (array) ($checkout->quote ?? []);

        $payload = [
            'items' => [[
                'title' => $title,
                'quantity' => 1,
                'unit_price' => (float) $checkout->total,
                'currency_id' => (string) ($quote['currency'] ?? ''),
            ]],
            'external_reference' => 'CRM-'.$checkout->id,
            'notification_url' => $config->webhookUrl(),
            'expires' => true,
            'expiration_date_from' => now()->toIso8601String(),
            'expiration_date_to' => $checkout->payment_expires_at?->toIso8601String(),
            'back_urls' => [
                'success' => $returnUrl,
                'failure' => $returnUrl,
                'pending' => $returnUrl,
            ],
            // Sin efectivo ni cajero: se pagan días después de que el pedido
            // venció y liberó el stock.
            'payment_methods' => [
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
            ],
        ];

        if (! empty($checkout->email)) {
            $payload['payer'] = ['email' => $checkout->email];
        }

        if (! empty($config->statement_descriptor)) {
            $payload['statement_descriptor'] = $config->statement_descriptor;
        }

        if ($this->allowsAutoReturn($returnUrl)) {
            $payload['auto_return'] = 'approved';
        }

        $response = $this->send(fn () => $this->client($config)->post('/checkout/preferences', $payload));

        $id = $response->json('id');
        $initPoint = $response->json('init_point');
        if (! is_string($id) || ! is_string($initPoint) || $initPoint === '') {
            throw new CheckoutException(self::GENERIC_ERROR);
        }

        return ['id' => $id, 'init_point' => $initPoint];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CheckoutException
     */
    public function payment(MercadoPagoConfig $config, string $paymentId): array
    {
        return (array) $this->send(fn () => $this->client($config)->get('/v1/payments/'.rawurlencode($paymentId)))->json();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws CheckoutException
     */
    public function searchPayments(MercadoPagoConfig $config, string $externalReference): array
    {
        $response = $this->send(fn () => $this->client($config)->get('/v1/payments/search', [
            'external_reference' => $externalReference,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]));

        return array_values(array_filter((array) $response->json('results', []), 'is_array'));
    }

    /**
     * Vence la preferencia para que el link deje de aceptar pagos. Una falla
     * solo se loguea: un pago tardío entra igual por el webhook.
     */
    public function expirePreference(MercadoPagoConfig $config, string $preferenceId): void
    {
        try {
            $response = $this->client($config)->put('/checkout/preferences/'.rawurlencode($preferenceId), [
                'expires' => true,
                'expiration_date_to' => now()->toIso8601String(),
            ]);

            if (! $response->successful()) {
                Log::warning('No se pudo vencer la preferencia de Mercado Pago', [
                    'preference_id' => $preferenceId,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo vencer la preferencia de Mercado Pago', [
                'preference_id' => $preferenceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function allowsAutoReturn(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? null) === 'https'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1'], true);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws CheckoutException
     */
    private function send(callable $call, bool $allowUnauthorized = false): Response
    {
        try {
            $response = $call();
        } catch (CheckoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('No se pudo contactar a Mercado Pago', ['error' => $e->getMessage()]);

            throw new CheckoutException(self::GENERIC_ERROR, previous: $e);
        }

        if ($response->status() === 401) {
            if ($allowUnauthorized) {
                return $response;
            }

            throw new CheckoutException(self::INVALID_TOKEN);
        }

        if (! $response->successful()) {
            Log::warning('Mercado Pago respondió con error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new CheckoutException(self::GENERIC_ERROR);
        }

        return $response;
    }

    /**
     * @throws CheckoutException si el tenant no tiene credencial legible.
     */
    private function client(MercadoPagoConfig $config): PendingRequest
    {
        $token = $config->getDecryptedAccessToken();
        if (! $token) {
            throw new CheckoutException(self::INVALID_TOKEN);
        }

        return $this->http($token);
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->baseUrl(self::BASE_URL);
    }
}
