<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\DeletedReservationRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function iterator_to_array;

/**
 * @see DeletedReservationRepository
 */
final class DeletedReservationRepositoryTest extends KernelTestCase
{
    private const string FIRST_ATTEMPT_AT = '2020-01-01 00:00:00';

    private const string REINDEX_STARTED_AT = '2020-06-01 00:00:00';

    private const string RETRY_AT = '2021-01-01 00:00:00';

    private DeletedReservationRepository $deletedReservationRepository;

    private Ulid $reservationId;

    protected function setUp(): void
    {
        self::bootKernel();

        $deletedReservationRepository = self::getContainer()->get(DeletedReservationRepository::class);
        self::assertInstanceOf(DeletedReservationRepository::class, $deletedReservationRepository);
        $this->deletedReservationRepository = $deletedReservationRepository;

        $this->reservationId = new Ulid();
    }

    /**
     * @return array<int, string> base32 ULIDs
     */
    private function findIdsDeletedSince(string $since): array
    {
        return array_map(
            static fn (Ulid $id): string => $id->toBase32(),
            iterator_to_array(
                $this->deletedReservationRepository->iterateIdsOfReservationsDeletedSince(
                    new DateTimeImmutable($since),
                ),
                false,
            ),
        );
    }

    public function testARetriedTombstoneIsCaughtUpByAReindexStartedAfterTheFirstAttempt(): void
    {
        $this->deletedReservationRepository->insertTombstones(
            [$this->reservationId],
            new DateTimeImmutable(self::FIRST_ATTEMPT_AT),
        );
        self::assertNotContains($this->reservationId->toBase32(), $this->findIdsDeletedSince(self::REINDEX_STARTED_AT));
        self::assertContains($this->reservationId->toBase32(), $this->findIdsDeletedSince(self::FIRST_ATTEMPT_AT));

        $this->deletedReservationRepository->insertTombstones(
            [$this->reservationId],
            new DateTimeImmutable(self::RETRY_AT),
        );

        self::assertContains($this->reservationId->toBase32(), $this->findIdsDeletedSince(self::REINDEX_STARTED_AT));
    }
}
