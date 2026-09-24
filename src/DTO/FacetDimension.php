<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class FacetDimension
{
    /**
     * @param array<int, FacetBucket> $buckets
     * @param int $omittedReservationCount reservations the buckets left out, zero where the size covers the dimension
     */
    public function __construct(
        public array $buckets,
        public int $omittedReservationCount,
    ) {
    }
}
