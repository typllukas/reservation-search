<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class HotelSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $chain,
    ) {
    }
}
