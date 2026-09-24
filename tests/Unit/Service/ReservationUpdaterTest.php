<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\ReservationUpdateInput;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Message\ReservationChanged;
use App\Service\ReservationUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_column;

/**
 * @see ReservationUpdater
 */
final class ReservationUpdaterTest extends TestCase
{
    private function createReservation(): Reservation
    {
        $reservation = new Reservation();
        $reservation
            ->setStatus(ReservationStatus::CONFIRMED)
            ->setPaid(true);

        return $reservation;
    }

    /**
     * @param array<int, array{call: string, message?: object}> $calls
     */
    private function createUpdater(array &$calls): ReservationUpdater
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = ['call' => 'persist'];
            });
        $entityManager->method('flush')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = ['call' => 'flush'];
            });

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$calls): Envelope {
                $calls[] = [
                    'call' => 'dispatch',
                    'message' => $message,
                ];

                return new Envelope($message);
            });

        return new ReservationUpdater($entityManager, $messageBus, self::createStub(LoggerInterface::class));
    }

    public function testFieldsLeftOutOfThePayloadKeepTheirValue(): void
    {
        $reservation = $this->createReservation();
        $calls = [];

        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
        self::assertTrue($reservation->isPaid());

        $this->createUpdater($calls)
            ->update($reservation, new ReservationUpdateInput(status: ReservationStatus::CANCELLED), false);

        self::assertSame(ReservationStatus::CANCELLED, $reservation->getStatus());
        self::assertTrue($reservation->isPaid());
    }

    public function testPaidCanBeSetToFalseAndIsNotConfusedWithNotProvided(): void
    {
        $reservation = $this->createReservation();
        $calls = [];

        self::assertTrue($reservation->isPaid());

        $this->createUpdater($calls)->update($reservation, new ReservationUpdateInput(paid: false), false);

        self::assertFalse($reservation->isPaid());
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
    }

    public function testAFailingProjectionIsLoggedAndDoesNotTurnASavedChangeIntoAnError(): void
    {
        $reservation = $this->createReservation();

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')
            ->willThrowException(new HandlerFailedException(
                new Envelope(new ReservationChanged($reservation->getId())),
                [new SearchUnavailableException('Writing to the search index failed.')],
            ));

        $loggedContexts = [];
        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->willReturnCallback(
                static function (string|Stringable $message, array $context) use (&$loggedContexts): void {
                    $loggedContexts[] = $context;
                },
            );

        $reservationUpdater = new ReservationUpdater(
            self::createStub(EntityManagerInterface::class),
            $messageBus,
            $logger,
        );

        $reservationUpdater->update($reservation, new ReservationUpdateInput(paid: false), false);

        self::assertFalse($reservation->isPaid());

        self::assertCount(1, $loggedContexts);
        self::assertSame($reservation->getId()->toBase32(), $loggedContexts[0]['reservation_id'] ?? null);
        self::assertArrayHasKey('exception', $loggedContexts[0]);
    }

    public function testEveryUpdateAnnouncesTheChangeWithTheReservationIdentifier(): void
    {
        $reservation = $this->createReservation();
        $calls = [];

        $this->createUpdater($calls)->update($reservation, new ReservationUpdateInput(paid: false), false);

        $dispatchedMessages = array_column($calls, 'message');

        self::assertCount(1, $dispatchedMessages);
        $message = $dispatchedMessages[0];
        self::assertInstanceOf(ReservationChanged::class, $message);
        self::assertSame($reservation->getId()->toBase32(), $message->reservationId->toBase32());
    }

    public function testWithFlushTheChangeIsWrittenBeforeItIsAnnounced(): void
    {
        $calls = [];

        $this->createUpdater($calls)->update($this->createReservation(), new ReservationUpdateInput(paid: false), true);

        self::assertSame(['persist', 'flush', 'dispatch'], array_column($calls, 'call'));
    }

    public function testWithoutFlushTheWriteIsLeftToTheCallerAndTheAnnouncementStillGoesOut(): void
    {
        $calls = [];

        $this->createUpdater($calls)
            ->update($this->createReservation(), new ReservationUpdateInput(paid: false), false);

        self::assertSame(['persist', 'dispatch'], array_column($calls, 'call'));
    }
}
