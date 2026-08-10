<?php

namespace Tests\Unit;

use App\Services\MailHtmlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailHtmlSanitizerTest extends TestCase
{
    #[Test]
    public function it_preserves_safe_rich_content_and_removes_executable_markup(): void
    {
        $result = app(MailHtmlSanitizer::class)->sanitize(
            '<script>alert(1)</script><p style="color: red; position: fixed">Hola <strong>equipo</strong></p>'.
            '<a href="javascript:alert(1)" onclick="alert(1)">malo</a>',
        );

        $this->assertStringContainsString('<strong>equipo</strong>', $result['html']);
        $this->assertStringContainsString('style="color: red"', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('javascript:', $result['html']);
        $this->assertStringNotContainsString('onclick', $result['html']);
    }

    #[Test]
    public function it_blocks_remote_images_until_the_operator_loads_them(): void
    {
        $result = app(MailHtmlSanitizer::class)->sanitize(
            '<p>Factura</p><img src="https://tracker.example/pixel.gif" alt="Factura">',
        );

        $this->assertTrue($result['has_remote_images']);
        $this->assertStringContainsString('data-remote-src="https://tracker.example/pixel.gif"', $result['html']);
        $this->assertStringNotContainsString(' src="https://tracker.example', $result['html']);
    }
}
