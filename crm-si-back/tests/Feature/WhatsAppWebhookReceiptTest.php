<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppWebhookReceiptJob;
use App\Models\WhatsAppWebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsWhatsAppWebhooks;
use Tests\TestCase;

class WhatsAppWebhookReceiptTest extends TestCase
{
    use RefreshDatabase;
    use SignsWhatsAppWebhooks;

    public function test_webhook_is_persisted_and_queued_before_acknowledgement(): void
    {
        Queue::fake();
        $payload = $this->payload('messages', ['statuses' => [['id' => 'wamid.A', 'status' => 'delivered']]]);

        $this->postWhatsAppWebhook($payload)
            ->assertOk()
            ->assertJson(['status' => 'EVENT_RECEIVED']);

        $receipt = WhatsAppWebhookReceipt::sole();
        $this->assertSame('pending', $receipt->status);
        $this->assertSame('whatsapp-webhooks', $receipt->queue_name);
        $this->assertSame($payload['entry'], $receipt->payload['entry']);
        Queue::assertPushedOn('whatsapp-webhooks', ProcessWhatsAppWebhookReceiptJob::class);
    }

    public function test_duplicate_meta_delivery_reuses_the_receipt(): void
    {
        Queue::fake();
        $payload = $this->payload('history', ['history' => [['metadata' => ['progress' => 0], 'threads' => []]]]);

        $this->postWhatsAppWebhook($payload)->assertOk();
        $this->postWhatsAppWebhook($payload)->assertOk();

        $this->assertSame(1, WhatsAppWebhookReceipt::count());
        Queue::assertPushed(ProcessWhatsAppWebhookReceiptJob::class, 2);
        Queue::assertPushedOn('whatsapp-sync', ProcessWhatsAppWebhookReceiptJob::class);
    }

    private function payload(string $field, array $value): array
    {
        return ['entry' => [[
            'id' => 'WABA_TEST',
            'changes' => [['field' => $field, 'value' => $value]],
        ]]];
    }
}
