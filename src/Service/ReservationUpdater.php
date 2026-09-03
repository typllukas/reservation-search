<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ReservationUpdateInput;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Message\ReservationChanged;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ReservationUpdater
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Passing flush false indexes before PreUpdate runs, so the document keeps the old updated_at,
     * which is the sort key.
     */
    public function update(Reservation $reservation, ReservationUpdateInput $input, bool $flush): void
    {
        if ($input->status instanceof ReservationStatus) {
            $reservation->setStatus($input->status);
        }

        if ($input->paid !== null) {
            $reservation->setPaid($input->paid);
        }

        $this->entityManager->persist($reservation);

        if ($flush) {
            $this->entityManager->flush();
        }

        try {
            $this->messageBus->dispatch(new ReservationChanged($reservation->getId()));
        } catch (HandlerFailedException $exception) {
            // the write stands; the drift is caught up by reservation-search:index:reindex
            $this->logger->error('The reservation was written, but the index could not be caught up.', [
                'exception' => $exception,// the handler formats it
                'reservation_id' => $reservation->getId()->toBase32(),
            ]);
        }
    }
}
