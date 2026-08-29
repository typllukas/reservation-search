<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletedReservation;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

use function array_fill;
use function count;
use function implode;

/**
 * @extends ServiceEntityRepository<DeletedReservation>
 */
class DeletedReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletedReservation::class);
    }

    /**
     * @return iterable<Ulid>
     */
    public function iterateIdsOfReservationsDeletedSince(DateTimeImmutable $since): iterable
    {
        $rows = $this->createQueryBuilder('deletedReservation')
            ->select('deletedReservation.id')
            ->where('deletedReservation.deletedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->toIterable();

        foreach ($rows as $row) {
            yield $row['id'];
        }
    }

    /**
     * A retried purge writes the same ids again, and the catch-up only sees tombstones newer than the reindex start.
     * DQL has no INSERT, so the upsert cannot be built.
     *
     * @param array<int, Ulid> $reservationIds
     */
    public function insertTombstones(array $reservationIds, DateTimeImmutable $deletedAt): void
    {
        if ($reservationIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($reservationIds), '(?, ?)'));
        $parameters = [];
        $types = [];
        foreach ($reservationIds as $reservationId) {
            $parameters[] = $reservationId->toBinary();
            $types[] = ParameterType::BINARY;
            $parameters[] = $deletedAt->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'INSERT INTO deleted_reservation (id, deleted_at) VALUES ' . $placeholders
                    . ' ON DUPLICATE KEY UPDATE deleted_at = VALUES(deleted_at)',
                $parameters,
                $types,
            );
    }
}
