<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use App\Message\ReservationChanged;
use App\Message\ReservationsDeleted;
use Symfony\Component\Uid\Ulid;

final class ReservationChangedAnnouncements
{
    public function announceWithoutWriting(Ulid $reservationId): ReservationChanged
    {
        return new ReservationChanged($reservationId);
    }

    /**
     * @param array<int, Ulid> $reservationIds
     */
    public function announceADifferentMessage(array $reservationIds): ReservationsDeleted
    {
        return new ReservationsDeleted($reservationIds);
    }
}
