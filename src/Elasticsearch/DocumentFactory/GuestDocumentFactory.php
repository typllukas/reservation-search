<?php

declare(strict_types=1);

namespace App\Elasticsearch\DocumentFactory;

use App\Entity\Guest;
use App\Helper\PhoneDigits;

/**
 * No derived counters: they would need synchronising on every reservation write.
 *
 * @phpstan-type GuestDocument array{
 *     name: string,
 *     email: string,
 *     phone: string,
 *     phone_digits: string,
 * }
 */
final class GuestDocumentFactory
{
    /**
     * @return GuestDocument
     */
    public function build(Guest $guest): array
    {
        return [
            'name' => $guest->getName(),
            'email' => $guest->getEmail(),
            'phone' => $guest->getPhone(),
            'phone_digits' => PhoneDigits::normalize($guest->getPhone()),
        ];
    }
}
