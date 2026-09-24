<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ReservationSort;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Enum\RoomKind;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReservationSearchInput
{
    /**
     * @param array<int, ReservationStatus>|null $status
     * @param array<int, ReservationSource>|null $source
     * @param array<int, Ulid>|null $hotel
     * @param array<int, RoomKind>|null $roomKind
     */
    public function __construct(
        #[Assert\Length(max: 200)]
        public ?string $text = null,
        public ?array $status = null,
        public ?array $source = null,
        public ?array $hotel = null,
        public ?array $roomKind = null,
        #[Assert\Date]
        public ?string $arrivalFrom = null,
        #[Assert\Date]
        #[Assert\GreaterThanOrEqual(propertyPath: 'arrivalFrom')]
        public ?string $arrivalTo = null,
        #[Assert\PositiveOrZero]
        public ?int $priceFrom = null,
        #[Assert\PositiveOrZero]
        #[Assert\GreaterThanOrEqual(propertyPath: 'priceFrom')]
        public ?int $priceTo = null,
        public ReservationSort $sort = ReservationSort::RELEVANCE,
        #[Assert\Range(min: 1, max: 100)]
        public int $size = 20,
        public ?string $cursor = null,
    ) {
    }
}
