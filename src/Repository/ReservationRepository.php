<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\ReservationRoom;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function count;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return iterable<array<int, Ulid>>
     */
    public function iterateIdBatches(int $batchSize): iterable
    {
        yield from $this->batchIds(
            $this->createQueryBuilder('reservation')->select('reservation.id')->getQuery()->toIterable(),
            $batchSize,
        );
    }

    /**
     * The document copies the guest's and the hotel's name, so a rename changes it without touching
     * reservation.updated_at.
     *
     * @return iterable<array<int, Ulid>>
     */
    public function iterateIdBatchesForChangesSince(DateTimeImmutable $since, int $batchSize): iterable
    {
        yield from $this->batchIds(
            $this->createQueryBuilder('reservation')
                ->select('reservation.id')
                ->join('reservation.guest', 'guest')
                ->join('reservation.hotel', 'hotel')
                ->where('reservation.updatedAt >= :since')
                ->orWhere('guest.updatedAt >= :since')
                ->orWhere('hotel.updatedAt >= :since')
                ->setParameter('since', $since)
                ->getQuery()
                ->toIterable(),
            $batchSize,
        );
    }

    /**
     * A ULID list bound without toBinary() and ArrayParameterType::BINARY matches nothing, no
     * error, zero rows; the column is BINARY(16).
     *
     * @param array<int, Ulid> $ids
     *
     * @return array<int, Reservation>
     */
    public function findForIndexing(array $ids): array
    {
        $binaryIds = array_map(static fn (Ulid $id): string => $id->toBinary(), $ids);

        return $this->createQueryBuilder('reservation')
            ->addSelect('guest', 'hotel', 'room')
            ->join('reservation.guest', 'guest')
            ->join('reservation.hotel', 'hotel')
            ->leftJoin('reservation.rooms', 'room')
            ->where('reservation.id IN (:ids)')
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    /**
     * Newest first: a ULID sorts by creation time and the column is BINARY(16), so the primary key
     * is already that order.
     *
     * @return array<int, Ulid>
     */
    public function findNewestIds(int $limit): array
    {
        $rows = $this->createQueryBuilder('reservation')
            ->select('reservation.id')
            ->orderBy('reservation.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Ulid => $row['id'], $rows);
    }

    /**
     * @return iterable<array<int, Ulid>>
     */
    public function iterateIdBatchesForDeparturesBefore(DateTimeImmutable $departedBefore, int $batchSize): iterable
    {
        yield from $this->batchIds(
            $this->createQueryBuilder('reservation')
                ->select('reservation.id')
                ->where('reservation.departure < :departedBefore')
                ->setParameter('departedBefore', $departedBefore)
                ->getQuery()
                ->toIterable(),
            $batchSize,
        );
    }

    /**
     * Bulk DQL fires no callback and runs no cascade.
     *
     * @param array<int, Ulid> $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $binaryIds = array_map(static fn (Ulid $id): string => $id->toBinary(), $ids);
        $entityManager = $this->getEntityManager();

        $entityManager->createQueryBuilder()
            ->delete(ReservationRoom::class, 'room')
            ->where('room.reservation IN (:ids)')
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->getQuery()
            ->execute();
        $entityManager->createQueryBuilder()
            ->delete(Reservation::class, 'reservation')
            ->where('reservation.id IN (:ids)')
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->getQuery()
            ->execute();
    }

    /**
     * toIterable() refuses a fetch join on a collection, so only the ids stream here.
     *
     * @param iterable<array{id: Ulid}> $rows
     *
     * @return iterable<array<int, Ulid>>
     */
    private function batchIds(iterable $rows, int $batchSize): iterable
    {
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row['id'];

            if (count($batch) !== $batchSize) {
                continue;
            }

            yield $batch;

            $batch = [];
        }

        if ($batch === []) {
            return;
        }

        yield $batch;
    }
}
