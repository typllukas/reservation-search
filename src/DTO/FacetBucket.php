<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Counted without that dimension's own filter, so filtering to "confirmed" keeps "cancelled" in the status facet.
 */
final readonly class FacetBucket
{
    public function __construct(
        public string $value,
        public int $count,
        public ?string $label,
        public ?string $code,
    ) {
    }
}
