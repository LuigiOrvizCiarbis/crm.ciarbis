<?php

namespace Tests\Unit;

use App\Support\ContactCustomDataNormalizer;
use PHPUnit\Framework\TestCase;

class ContactCustomDataNormalizerTest extends TestCase
{
    /**
     * Los CSV de clientes vienen en d/m/Y. Antes se rompían dos veces: el día
     * mayor a 12 tiraba excepción y quedaba sin importar, y el menor a 12
     * entraba en silencio con el mes y el día dados vuelta.
     */
    public function test_it_parses_day_first_dates(): void
    {
        $this->assertSame('2021-05-31', ContactCustomDataNormalizer::normalizeDate('31/05/2021'));
        $this->assertSame('2020-07-11', ContactCustomDataNormalizer::normalizeDate('11/07/2020'));
        $this->assertSame('2020-03-20', ContactCustomDataNormalizer::normalizeDate('20-03-2020'));
        $this->assertSame('2020-03-22', ContactCustomDataNormalizer::normalizeDate('22.03.2020'));
        $this->assertSame('2021-05-31', ContactCustomDataNormalizer::normalizeDate('31/05/21'));
    }

    public function test_it_still_parses_iso_and_datetime_values(): void
    {
        $this->assertSame('2026-09-03', ContactCustomDataNormalizer::normalizeDate('2026-09-03'));
        $this->assertSame('2026-09-03', ContactCustomDataNormalizer::normalizeDate('2026-09-03 14:22:01'));
        $this->assertSame('2026-09-03', ContactCustomDataNormalizer::normalizeDate('2026-09-03T14:22:01+00:00'));
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $this->assertSame('2021-05-31', ContactCustomDataNormalizer::normalizeDate('  31/05/2021  '));
    }

    public function test_it_rejects_impossible_and_empty_values(): void
    {
        $this->assertNull(ContactCustomDataNormalizer::normalizeDate('31/02/2021'));
        $this->assertNull(ContactCustomDataNormalizer::normalizeDate('tienda nube'));
        $this->assertNull(ContactCustomDataNormalizer::normalizeDate(''));
        $this->assertNull(ContactCustomDataNormalizer::normalizeDate('   '));
        $this->assertNull(ContactCustomDataNormalizer::normalizeDate(null));
        $this->assertNull(ContactCustomDataNormalizer::normalizeDate(20210531));
    }
}
