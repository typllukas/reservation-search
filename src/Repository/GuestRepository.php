<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guest;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

use function array_map;

/**
 * @extends ServiceEntityRepository<Guest>
 */
class GuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guest::class);
    }

    /**
     * @return iterable<Guest>
     */
    public function iterateAll(): iterable
    {
        return $this->createQueryBuilder('guest')
            ->getQuery()
            ->toIterable();
    }

    /**
     * @return iterable<Guest>
     */
    public function iterateChangedSince(DateTimeImmutable $since): iterable
    {
        return $this->createQueryBuilder('guest')
            ->where('guest.updatedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->toIterable();
    }

    /**
     * @see ReservationRepository::findNewestIds()
     *
     * @return array<int, Ulid>
     */
    public function findNewestIds(int $limit): array
    {
        $rows = $this->createQueryBuilder('guest')
            ->select('guest.id')
            ->orderBy('guest.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): Ulid => $row['id'], $rows);
    }
}
