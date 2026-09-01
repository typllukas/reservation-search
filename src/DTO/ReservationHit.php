<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;

final readonly class ReservationHit
{
    /**
     * @param array<string, array<int, string>> $highlight
     */
    public function __construct(
        public string $id,
        public string $number,
        public ReservationStatus $status,
        public ReservationSource $source,
        public string $arrival,
        public string $departure,
        public int $nights,
        public int $totalPrice,
        public bool $paid,
        public ?string $note,
        public GuestSummary $guest,
        public HotelSummary $hotel,
        public array $highlight,
    ) {
    }
}
