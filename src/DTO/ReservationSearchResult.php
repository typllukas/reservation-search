<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ReservationSearchResult
{
    /**
     * @param array<int, ReservationHit> $items
     * @param array<string, FacetDimension> $facets values with counts, keyed by dimension
     * @param string|null $nextCursor null on the last page, which is the one shorter than the page size
     */
    public function __construct(
        public int $total,
        public int $tookMs,
        public array $items,
        public ?string $nextCursor,
        public array $facets,
    ) {
    }
}
