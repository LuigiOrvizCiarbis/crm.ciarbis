<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Events\MessageEdited;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_an_ok_preview_from_og_tags(): void
    {
        Http::fake([
            'https://example.com/article' => Http::response($this->htmlWithOgTags(), 200, ['Content-Type' => 'text/html']),
        ]);
        Event::fake([MessageEdited::class]);

        // QUEUE_CONNECTION=sync en tests: MessageObserver::created ya dispara
        // el job inline al crear el mensaje, sin necesidad de despacharlo a mano.
        $message = $this->messageWithLink('Mirá esto https://example.com/article');

        $preview = LinkPreview::first();
        $this->assertNotNull($preview);
        $this->assertSame('ok', $preview->status);
        $this->assertSame('Título del artículo', $preview->title);
        $this->assertSame('Una descripción de prueba.', $preview->description);
        $this->assertSame('Example Site', $preview->site_name);
        $this->assertNotNull($preview->fetched_at);
        $this->assertSame($preview->id, $message->fresh()->link_preview_id);

        Event::assertDispatched(MessageEdited::class, fn (MessageEdited $event) => $event->message->id === $message->id);
    }

    public function test_reuses_a_fresh_ok_preview_without_refetching(): void
    {
        Http::fake([
            'https://example.com/article' => Http::response($this->htmlWithOgTags(), 200, ['Content-Type' => 'text/html']),
        ]);

        $first = $this->messageWithLink('https://example.com/article');
        $this->assertSame(1, Http::recorded()->count());

        $second = $this->messageWithLink('Otra vez https://example.com/article');

        // No se volvió a pegarle a la URL: se reasoció la misma preview.
        $this->assertSame(1, Http::recorded()->count());
        $this->assertSame($first->fresh()->link_preview_id, $second->fresh()->link_preview_id);
        $this->assertSame(1, LinkPreview::count());
    }

    public function test_marks_failed_on_non_html_content_type(): void
    {
        Http::fake([
            'https://example.com/file.pdf' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $message = $this->messageWithLink('https://example.com/file.pdf');

        $preview = LinkPreview::first();
        $this->assertSame('failed', $preview->status);
        $this->assertNotNull($preview->failed_at);
        $this->assertNotNull($preview->failure_reason);
    }

    public function test_marks_failed_on_connection_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $message = $this->messageWithLink('https://example.com/slow');

        $preview = LinkPreview::first();
        $this->assertSame('failed', $preview->status);
    }

    public function test_no_url_in_content_skips_dispatch(): void
    {
        Http::fake();

        $this->messageWithLink('Un mensaje sin ningún link.');

        $this->assertDatabaseCount('link_previews', 0);
        Http::assertNothingSent();
    }

    public function test_image_url_is_absolute_regardless_of_frontend_origin(): void
    {
        // Storage::disk('public')->url() puede devolver una ruta relativa
        // ("/storage/..."). Si LinkPreviewCard la usara tal cual, el <img>
        // la resolvería contra el origen de Next.js (que no tiene rewrite de
        // /storage), no el de Laravel: rota en cuanto front y API viven en
        // dominios separados. image_url debe venir siempre absoluta, mismo
        // criterio que Message::media_full_url.
        $preview = LinkPreview::create([
            'url_hash' => hash('sha256', 'https://example.com/with-image'),
            'url' => 'https://example.com/with-image',
            'image_path' => 'link-previews/abc123.jpg',
            'status' => 'ok',
        ]);

        $this->assertStringStartsWith('http', $preview->image_url);
        $this->assertStringContainsString('link-previews/abc123.jpg', $preview->image_url);
    }

    private function htmlWithOgTags(): string
    {
        return <<<'HTML'
            <html>
            <head>
                <meta property="og:title" content="Título del artículo" />
                <meta property="og:description" content="Una descripción de prueba." />
                <meta property="og:site_name" content="Example Site" />
            </head>
            <body></body>
            </html>
            HTML;
    }

    private function messageWithLink(string $content): Message
    {
        $tenant = Tenant::create(['name' => 'Tenant '.uniqid()]);

        return Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => null,
            'content' => $content,
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => null,
        ]);
    }
}
