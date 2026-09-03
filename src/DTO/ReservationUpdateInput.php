<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ReservationStatus;

/**
 * A false default on paid would cancel the payment on a status-only PATCH.
 */
final readonly class ReservationUpdateInput
{
    public function __construct(
        public ?ReservationStatus $status = null,
        public ?bool $paid = null,
    ) {
    }
}
