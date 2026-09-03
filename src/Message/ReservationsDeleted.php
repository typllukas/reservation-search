<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Ulid;

/**
 * One message per purge batch. Identifiers only, the rows are gone by the time it is delivered.
 */
final readonly class ReservationsDeleted
{
    /**
     * @param array<int, Ulid> $reservationIds
     */
    public function __construct(
        public array $reservationIds,
    ) {
    }
}
