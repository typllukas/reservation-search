<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Elasticsearch\ReservationIndexer;
use App\Message\ReservationChanged;
use App\Repository\ReservationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReservationChangedHandler
{
    public function __construct(
        private ReservationRepository $reservationRepository,
        private ReservationIndexer $reservationIndexer,
    ) {
    }

    public function __invoke(ReservationChanged $reservationChanged): void
    {
        $reservation = $this->reservationRepository->find($reservationChanged->reservationId);
        if ($reservation === null) {
            return;
        }

        $this->reservationIndexer->index($reservation);
    }
}
