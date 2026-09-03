<?php

namespace Tests\Feature\Support;

use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use App\Support\SizeLimitedWriterState;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicUrlGuardTest extends TestCase
{
    public function test_rejects_loopback_ip(): void
    {
        $this->expectException(PublicUrlRejectedException::class);
        app(PublicUrlGuard::class)->assertPublicUrl('http://127.0.0.1/');
    }

    public function test_rejects_private_rfc1918_ip(): void
    {
        $this->expectException(PublicUrlRejectedException::class);
        app(PublicUrlGuard::class)->assertPublicUrl('http://10.0.0.1/');
    }

    public function test_rejects_cloud_metadata_link_local_ip(): void
    {
        $this->expectException(PublicUrlRejectedException::class);
        app(PublicUrlGuard::class)->assertPublicUrl('http://169.254.169.254/latest/meta-data/');
    }

    public function test_rejects_localhost_hostname(): void
    {
        $this->expectException(PublicUrlRejectedException::class);
        app(PublicUrlGuard::class)->assertPublicUrl('http://localhost/');
    }

    public function test_rejects_file_scheme(): void
    {
        $this->expectException(PublicUrlRejectedException::class);
        app(PublicUrlGuard::class)->assertPublicUrl('file:///etc/passwd');
    }

    public function test_rejects_non_standard_port(): void
    {
        $this->expectException(PublicUrlRejectedException::class);
        app(PublicUrlGuard::class)->assertPublicUrl('http://example.com:22/');
    }

    public function test_accepts_public_ip_literal(): void
    {
        [$host, $port, $ips] = app(PublicUrlGuard::class)->assertPublicUrl('https://93.184.216.34/');

        $this->assertSame('93.184.216.34', $host);
        $this->assertSame(443, $port);
        $this->assertSame(['93.184.216.34'], $ips);
    }

    public function test_fetch_rejects_redirect_from_public_ip_to_private_host(): void
    {
        // assertPublicUrl valida por IP resuelta, no por hostname: se usa un
        // literal IP público (no requiere DNS real) que Http::fake responde
        // con un redirect hacia el metadata endpoint del cloud. El segundo
        // hop del bucle de fetch() debe revalidar esa nueva URL y rechazarla,
        // aunque el primer hop haya sido una IP pública legítima.
        Http::fake([
            'https://93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $this->expectException(PublicUrlRejectedException::class);

        app(PublicUrlGuard::class)->fetch('https://93.184.216.34/');
    }

    public function test_size_limited_writer_aborts_transfer_past_the_limit(): void
    {
        // Regresión: la primera versión de fetch() confiaba en truncar
        // ->body() DESPUÉS de que cURL ya había materializado la respuesta
        // completa en memoria, así que un servidor que manda varios GB
        // tumbaba al worker pese al límite "advertido". El writer real
        // (invocado por CURLOPT_WRITEFUNCTION) debe cortar la transferencia
        // en el momento en que un chunk hace superar el límite, sin esperar
        // a ver el body completo. Se testea el callback aislado, sin pasar
        // por cURL/red real: eso es justo lo que garantiza que corte antes
        // de acumular más de lo permitido, no después.
        $state = new SizeLimitedWriterState;
        $writer = PublicUrlGuard::sizeLimitedWriter($state, limit: 10);

        $this->assertSame(5, $writer(null, 'aaaaa'));
        $this->assertFalse($state->exceeded);
        $this->assertSame('aaaaa', $state->received);

        // Este chunk lleva el total a 15 > 10: el writer debe cortar acá,
        // no seguir aceptando datos hasta terminar la respuesta.
        $this->assertSame(0, $writer(null, 'bbbbbbbbbb'));
        $this->assertTrue($state->exceeded);
        $this->assertTrue($state->invoked);
    }

    public function test_size_limited_writer_accepts_body_within_the_limit(): void
    {
        $state = new SizeLimitedWriterState;
        $writer = PublicUrlGuard::sizeLimitedWriter($state, limit: 100);

        $this->assertSame(4, $writer(null, 'data'));
        $this->assertFalse($state->exceeded);
        $this->assertSame('data', $state->received);
    }
}
