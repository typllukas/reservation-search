<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use App\Entity\Reservation;
use App\Entity\ReservationRoom;

final class ReservationWriteCalls
{
    public function writeAFieldTheUpdaterWrites(Reservation $reservation): void
    {
        $reservation->setPaid(true);
    }

    public function writeAFieldTheUpdaterDoesNotWrite(Reservation $reservation): void
    {
        $reservation->setNote('indexed all the same, and the document keeps the old note');
    }

    public function writeTheNestedRooms(Reservation $reservation, ReservationRoom $room): void
    {
        $reservation->addRoom($room);
    }

    public function readAReservation(Reservation $reservation): string
    {
        if (!$reservation->isPaid()) {
            return '';
        }

        return $reservation->getStatus()->value;
    }

    public function countTheRoomsOnTheCollection(Reservation $reservation): int
    {
        return $reservation->getRooms()->count();
    }

    public function writeARoomOfTheReservation(ReservationRoom $room): ReservationRoom
    {
        return $room->setPrice(250000);
    }

    public function writeTheSharedGuest(Reservation $reservation): void
    {
        $reservation->getGuest()->setName('a rename reaches every reservation of this guest, so a reindex fixes it');
    }
}
