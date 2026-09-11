<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Message\ReservationsDeleted;
use App\Repository\DeletedReservationRepository;
use App\Repository\ReservationRepository;
use App\Service\ReservationPurger;
use ArrayIterator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

use function array_column;
use function array_filter;
use function array_values;

/**
 * @see ReservationPurger
 *
 * @phpstan-type PurgerCall array{
 *     call: string,
 *     ids: array<int, Ulid>,
 *     deletedAt?: DateTimeImmutable,
 *     departedBefore?: DateTimeImmutable,
 * }
 */
final class ReservationPurgerTest extends TestCase
{
    /**
     * @param array<int, array<int, Ulid>> $batches
     * @param array<int, PurgerCall> $calls
     */
    private function createPurger(array $batches, array &$calls): ReservationPurger
    {
        $reservationRepository = self::createStub(ReservationRepository::class);
        $reservationRepository->method('iterateIdBatchesForDeparturesBefore')
            ->willReturnCallback(
                static function (DateTimeImmutable $departedBefore) use ($batches, &$calls): ArrayIterator {
                    $calls[] = [
                        'call' => 'iterateIdBatchesForDeparturesBefore',
                        'ids' => [],
                        'departedBefore' => $departedBefore,
                    ];

                    return new ArrayIterator($batches);
                },
            );
        $reservationRepository->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$calls): void {
                $calls[] = [
                    'call' => 'deleteByIds',
                    'ids' => $ids,
                ];
            });

        $deletedReservationRepository = self::createStub(DeletedReservationRepository::class);
        $deletedReservationRepository->method('insertTombstones')
            ->willReturnCallback(static function (array $ids, DateTimeImmutable $deletedAt) use (&$calls): void {
                $calls[] = [
                    'call' => 'insertTombstones',
                    'ids' => $ids,
                    'deletedAt' => $deletedAt,
                ];
            });

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$calls): Envelope {
                self::assertInstanceOf(ReservationsDeleted::class, $message);
                $calls[] = [
                    'call' => 'dispatch',
                    'ids' => $message->reservationIds,
                ];

                return new Envelope($message);
            });

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static function (callable $work) use (&$calls): mixed {
                $calls[] = ['call' => 'transactionOpened', 'ids' => []];
                $result = $work();
                $calls[] = ['call' => 'transactionCommitted', 'ids' => []];

                return $result;
            },
        );

        return new ReservationPurger(
            $reservationRepository,
            $deletedReservationRepository,
            $messageBus,
            $entityManager,
        );
    }

    public function testOneMessageIsSentPerBatchAndNotPerReservation(): void
    {
        $calls = [];

        $purgedReservations = $this->createPurger([[new Ulid(), new Ulid()], [new Ulid()]], $calls)
            ->purge(new DateTimeImmutable('2020-01-01'), static function (string $message): void {
            }, false);

        self::assertSame(3, $purgedReservations);

        $dispatchedBatches = array_values(
            array_filter($calls, static fn (array $call): bool => $call['call'] === 'dispatch'),
        );
        self::assertCount(2, $dispatchedBatches);
        self::assertCount(2, $dispatchedBatches[0]['ids']);
        self::assertCount(1, $dispatchedBatches[1]['ids']);
    }

    public function testTheTombstoneAndTheDeletionShareOneTransactionAndTheAnnouncementFollowsIt(): void
    {
        $ids = [new Ulid(), new Ulid()];
        $calls = [];

        $this->createPurger([$ids], $calls)
            ->purge(new DateTimeImmutable('2020-01-01'), static function (string $message): void {
            }, false);

        self::assertSame(
            [
                'iterateIdBatchesForDeparturesBefore',
                'transactionOpened',
                'insertTombstones',
                'deleteByIds',
                'transactionCommitted',
                'dispatch',
            ],
            array_column($calls, 'call'),
        );
        self::assertSame($ids, $calls[2]['ids']);
    }

    public function testADryRunCountsWithoutWritingAnything(): void
    {
        $calls = [];
        $progressMessages = [];

        $purgedReservations = $this->createPurger([[new Ulid(), new Ulid()], [new Ulid()]], $calls)
            ->purge(new DateTimeImmutable('2020-01-01'), static function (string $message) use (
                &$progressMessages,
            ): void {
                $progressMessages[] = $message;
            }, true);

        self::assertSame(3, $purgedReservations);
        self::assertSame(['iterateIdBatchesForDeparturesBefore'], array_column($calls, 'call'));
        self::assertSame(
            ['would delete 2 reservations (2 in total)', 'would delete 1 reservations (3 in total)'],
            $progressMessages,
        );
    }

    public function testOnlyReservationsThatDepartedBeforeTheCutoffAreLookedUp(): void
    {
        $calls = [];
        $cutoff = new DateTimeImmutable('2020-01-01');

        $this->createPurger([[new Ulid()]], $calls)
            ->purge($cutoff, static function (string $message): void {
            }, false);

        self::assertSame($cutoff, $calls[0]['departedBefore'] ?? null);
    }

    public function testEachBatchReportsWhatItDeletedAndTheRunningTotal(): void
    {
        $calls = [];
        $progressMessages = [];

        $this->createPurger([[new Ulid(), new Ulid()], [new Ulid()]], $calls)
            ->purge(new DateTimeImmutable('2020-01-01'), static function (string $message) use (
                &$progressMessages,
            ): void {
                $progressMessages[] = $message;
            }, false);

        self::assertSame(
            ['deleted 2 reservations (2 in total)', 'deleted 1 reservations (3 in total)'],
            $progressMessages,
        );
    }

    /**
     * A reindex starting mid-purge catches up from its own start; a tombstone stamped earlier leaves a ghost.
     */
    public function testTheDeletionTimeIsTakenPerBatchAndNotOncePerRun(): void
    {
        $calls = [];

        $this->createPurger([[new Ulid()], [new Ulid()]], $calls)
            ->purge(new DateTimeImmutable('2020-01-01'), static function (string $message): void {
            }, false);

        $tombstoneCalls = array_values(
            array_filter($calls, static fn (array $call): bool => $call['call'] === 'insertTombstones'),
        );
        self::assertCount(2, $tombstoneCalls);

        $deletedAtValues = array_column($tombstoneCalls, 'deletedAt');
        self::assertCount(2, $deletedAtValues);
        self::assertNotSame($deletedAtValues[0], $deletedAtValues[1]);
    }
}
