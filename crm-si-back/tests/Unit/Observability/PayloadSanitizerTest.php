<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\Langfuse\PayloadSanitizer;
use PHPUnit\Framework\TestCase;

class PayloadSanitizerTest extends TestCase
{
    private PayloadSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new PayloadSanitizer(100);
    }

    public function test_masks_email(): void
    {
        $this->assertSame('escribime a [EMAIL]', $this->sanitizer->sanitize('escribime a juan.perez@mail.com'));
    }

    public function test_masks_phone(): void
    {
        $this->assertSame('mi cel [TELEFONO]', $this->sanitizer->sanitize('mi cel +54 9 11 2345-6789'));
    }

    public function test_masks_card(): void
    {
        $this->assertSame('tarjeta [TARJETA]', $this->sanitizer->sanitize('tarjeta 4111 1111 1111 1111'));
    }

    public function test_keeps_dates_and_prices(): void
    {
        $text = 'turno el 2026-11-28 a $1.250.000';

        $this->assertSame($text, $this->sanitizer->sanitize($text));
    }

    public function test_keeps_date_times_but_masks_phones_next_to_them(): void
    {
        $this->assertSame('vence 2026-11-29 09:00', $this->sanitizer->sanitize('vence 2026-11-29 09:00'));
        $this->assertSame('creada 29-11-2026 09:00:00', $this->sanitizer->sanitize('creada 29-11-2026 09:00:00'));
        $this->assertSame('el 29.11.2026 llamar al [TELEFONO]', $this->sanitizer->sanitize('el 29.11.2026 llamar al 11 4567-8901'));
    }

    public function test_omits_base64_image_data(): void
    {
        $result = $this->sanitizer->sanitize(['type' => 'image', 'mime' => 'image/png', 'data' => str_repeat('A', 2000)]);

        $this->assertSame('[base64 omitido]', $result['data']);
        $this->assertSame('image/png', $result['mime']);
    }

    public function test_truncates_long_strings(): void
    {
        $result = $this->sanitizer->sanitize(str_repeat('x', 150));

        $this->assertStringStartsWith(str_repeat('x', 100), $result);
        $this->assertStringEndsWith('…[truncado 50 caracteres]', $result);
    }
}
