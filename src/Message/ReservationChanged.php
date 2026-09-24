<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Ulid;

final readonly class ReservationChanged
{
    public function __construct(
        public Ulid $reservationId,
    ) {
    }
}
