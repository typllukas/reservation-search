<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use App\DTO\ReservationUpdateInput;
use App\Entity\Reservation;
use App\Service\ReservationUpdater;
use Elastic\Elasticsearch\Client;

final readonly class ReservationUpdateCalls
{
    public function __construct(private ReservationUpdater $reservationUpdater)
    {
    }

    public function updateWithoutFlushing(Reservation $reservation, ReservationUpdateInput $input): void
    {
        $this->reservationUpdater->update($reservation, $input, false);
    }

    public function updateWithoutFlushingByName(Reservation $reservation, ReservationUpdateInput $input): void
    {
        $this->reservationUpdater->update(reservation: $reservation, input: $input, flush: false);
    }

    public function updateAndFlush(Reservation $reservation, ReservationUpdateInput $input): void
    {
        $this->reservationUpdater->update($reservation, $input, true);
    }

    public function updateAndFlushByName(Reservation $reservation, ReservationUpdateInput $input): void
    {
        $this->reservationUpdater->update(reservation: $reservation, input: $input, flush: true);
    }

    public function updateWithoutFlushingByNameOutOfOrder(Reservation $reservation, ReservationUpdateInput $input): void
    {
        $this->reservationUpdater->update(reservation: $reservation, input: $input, flush: false);
    }

    public function updateAndFlushByNameOutOfOrder(Reservation $reservation, ReservationUpdateInput $input): void
    {
        $this->reservationUpdater->update(reservation: $reservation, input: $input, flush: true);
    }

    public function updateSomethingOtherThanAReservation(Client $client, string $indexName): void
    {
        $client->update(['index' => $indexName, 'id' => '1', 'body' => ['doc' => []]]);
    }
}
