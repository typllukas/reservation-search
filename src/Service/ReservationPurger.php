<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\ReservationsDeleted;
use App\Repository\DeletedReservationRepository;
use App\Repository\ReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function count;
use function sprintf;

final readonly class ReservationPurger
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private ReservationRepository $reservationRepository,
        private DeletedReservationRepository $deletedReservationRepository,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     *
     * @return int the reservations deleted, or that a dry run would have deleted
     */
    public function purge(DateTimeImmutable $departedBefore, callable $reportProgress, bool $dryRun): int
    {
        $purgedReservations = 0;
        $progressVerb = $dryRun ? 'would delete' : 'deleted';

        $idBatches = $this->reservationRepository->iterateIdBatchesForDeparturesBefore(
            $departedBefore,
            self::BATCH_SIZE,
        );

        foreach ($idBatches as $ids) {
            if (!$dryRun) {
                // a tombstone left without the delete makes Reindexer::removeDeleted() drop a live document
                $this->entityManager->wrapInTransaction(function () use ($ids): void {
                    $this->deletedReservationRepository->insertTombstones($ids, new DateTimeImmutable());
                    $this->reservationRepository->deleteByIds($ids);
                });
                $this->messageBus->dispatch(new ReservationsDeleted($ids));
            }

            $purgedReservations += count($ids);
            $reportProgress(
                sprintf('%s %d reservations (%d in total)', $progressVerb, count($ids), $purgedReservations),
            );
        }

        return $purgedReservations;
    }
}
