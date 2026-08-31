<?php

namespace Tests\Unit;

use App\Support\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    #[Test]
    public function collapses_the_argentine_mobile_9(): void
    {
        $this->assertSame('542235550101', PhoneNumberNormalizer::dedupeKey('5492235550101'));
    }

    #[Test]
    public function treats_separators_and_plus_sign_the_same_as_raw_digits(): void
    {
        $this->assertSame('542235550101', PhoneNumberNormalizer::dedupeKey('+54 9 223 555-0101'));
    }

    #[Test]
    public function two_written_forms_of_the_same_number_produce_the_same_key(): void
    {
        $withNine = PhoneNumberNormalizer::dedupeKey('5492235550101');
        $withoutNine = PhoneNumberNormalizer::dedupeKey('542235550101');

        $this->assertSame($withNine, $withoutNine);
    }

    #[Test]
    public function leaves_numbers_without_the_argentine_mobile_prefix_untouched(): void
    {
        $this->assertSame('542235550101', PhoneNumberNormalizer::dedupeKey('542235550101'));
    }

    #[Test]
    public function leaves_united_states_numbers_untouched(): void
    {
        $this->assertSame('15558852936', PhoneNumberNormalizer::dedupeKey('+1 555-885-2936'));
    }

    #[Test]
    public function returns_null_for_empty_or_null_input(): void
    {
        $this->assertNull(PhoneNumberNormalizer::dedupeKey(null));
        $this->assertNull(PhoneNumberNormalizer::dedupeKey(''));
        $this->assertNull(PhoneNumberNormalizer::dedupeKey('   '));
    }
}
