<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * /api/whatsapp-webhook exige X-Hub-Signature-256: los tests mandan el body
 * firmado como lo hace Meta (HMAC-SHA256 del raw body con el app secret).
 */
trait SignsWhatsAppWebhooks
{
    protected function postWhatsAppWebhook(array $payload, ?string $signature = null): TestResponse
    {
        if (! config('services.facebook.app_secret')) {
            config(['services.facebook.app_secret' => 'test-app-secret']);
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/whatsapp-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, config('services.facebook.app_secret')),
        ], $body);
    }
}
