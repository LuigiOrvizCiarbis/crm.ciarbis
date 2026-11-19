<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MercadoPagoConfig;
use App\Services\Commerce\CheckoutException;
use App\Services\Commerce\MercadoPagoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Credencial de Mercado Pago del tenant. El access token es write-only.
 * Reusa los permisos de WooCommerce (grupo ecommerce).
 */
class MercadoPagoConfigController extends Controller
{
    private const INVALID_TOKEN = 'La credencial de Mercado Pago no es válida.';

    public function __construct(private readonly MercadoPagoClient $client) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.view') && ! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $config = MercadoPagoConfig::where('tenant_id', $user->tenant_id)->first();

        return response()->json(['data' => $this->transform($config)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'access_token' => ['nullable', 'string', 'max:255'],
            'statement_descriptor' => ['nullable', 'string', 'max:22'],
        ]);

        $config = MercadoPagoConfig::firstOrNew(['tenant_id' => $user->tenant_id]);
        $config->tenant_id = $user->tenant_id;

        $token = trim((string) ($validated['access_token'] ?? ''));
        if ($token !== '') {
            $this->assertValid($token);
            $config->setEncryptedAccessToken($token);
            $config->last_verified_at = now();
        }

        if (array_key_exists('statement_descriptor', $validated)) {
            $descriptor = trim((string) $validated['statement_descriptor']);
            $config->statement_descriptor = $descriptor === '' ? null : $descriptor;
        }

        $config->webhook_token ??= Str::random(48);
        $config->save();

        return response()->json(['data' => $this->transform($config->fresh())]);
    }

    public function test(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'access_token' => ['nullable', 'string', 'max:255'],
        ]);

        $config = MercadoPagoConfig::where('tenant_id', $user->tenant_id)->first();
        $token = trim((string) ($validated['access_token'] ?? '')) ?: $config?->getDecryptedAccessToken();

        if (! $token) {
            return response()->json(['message' => 'Cargá el access token de Mercado Pago.'], 422);
        }

        try {
            $result = $this->client->verify($token);
        } catch (CheckoutException $e) {
            return response()->json(['message' => 'No pudimos comunicarnos con Mercado Pago. Probá de nuevo.'], 422);
        }

        if ($result['ok'] && $config && empty($validated['access_token'])) {
            $config->forceFill(['last_verified_at' => now()])->save();
        }

        return response()->json(['data' => $result]);
    }

    private function assertValid(string $token): void
    {
        try {
            $ok = $this->client->verify($token)['ok'];
        } catch (CheckoutException $e) {
            throw ValidationException::withMessages(['access_token' => 'No pudimos comunicarnos con Mercado Pago. Probá de nuevo.']);
        }

        if (! $ok) {
            throw ValidationException::withMessages(['access_token' => self::INVALID_TOKEN]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(?MercadoPagoConfig $config): array
    {
        return [
            'has_credentials' => (bool) $config?->hasCredentials(),
            'statement_descriptor' => $config?->statement_descriptor,
            'last_verified_at' => $config?->last_verified_at?->toIso8601String(),
        ];
    }
}
