<?php

declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\PhoneDigits;
use PHPUnit\Framework\TestCase;

/**
 * @see PhoneDigits
 */
final class PhoneDigitsTest extends TestCase
{
    public function testEveryWayOfWritingOneNumberNormalisesToTheSameValue(): void
    {
        foreach (['+420 111 222 333', '00420111222333', '420 111 222 333', '420111222333'] as $written) {
            self::assertSame('420111222333', PhoneDigits::normalize($written));
        }
    }

    public function testNumberWrittenWithoutACountryCodeStaysAsTyped(): void
    {
        self::assertSame('111222333', PhoneDigits::normalize('111 222 333'));
    }
}
