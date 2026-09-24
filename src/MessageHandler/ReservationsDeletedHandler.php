<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Elasticsearch\ReservationIndexer;
use App\Message\ReservationsDeleted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReservationsDeletedHandler
{
    public function __construct(
        private ReservationIndexer $reservationIndexer,
    ) {
    }

    public function __invoke(ReservationsDeleted $reservationsDeleted): void
    {
        $this->reservationIndexer->deleteByIds($reservationsDeleted->reservationIds);
    }
}
