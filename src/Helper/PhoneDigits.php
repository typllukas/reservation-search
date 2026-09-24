<?php

declare(strict_types=1);

namespace App\Helper;

use function preg_replace;
use function str_starts_with;
use function substr;

/**
 * Bare digits, dialling code kept: 683410869 on its own may exist in dozens of countries.
 * The search box hands its whole text through here, so the input is not always a phone number.
 */
final class PhoneDigits
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // the plus already went with the other non-digits
        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }
}
