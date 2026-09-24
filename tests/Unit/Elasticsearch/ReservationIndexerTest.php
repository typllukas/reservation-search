<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\Elasticsearch\DocumentFactory\ReservationDocumentFactory;
use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\ReservationIndexer;
use App\Entity\Guest;
use App\Entity\Hotel;
use App\Entity\Reservation;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use DateTimeImmutable;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Ulid;

use function explode;
use function json_decode;
use function json_encode;
use function parse_str;
use function strval;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * @see ReservationIndexer
 */
final class ReservationIndexerTest extends TestCase
{
    private function createReservation(): Reservation
    {
        $reservation = new Reservation();
        $reservation
            ->setNumber('0000-000000')
            ->setGuest(new Guest()->setName('Test Guest')->setEmail('test@test.com')->setPhone('+00000000'))
            ->setHotel(new Hotel()->setCode('H-0000')->setName('Test Hotel')->setChain('Test Chain'))
            ->setStatus(ReservationStatus::CONFIRMED)
            ->setSource(ReservationSource::TRAVEL_AGENCY)
            ->setArrival(new DateTimeImmutable('2026-09-14'))
            ->setDeparture(new DateTimeImmutable('2026-09-17'))
            ->setTotalPrice(2)
            ->setPaid(true);

        $reservation->stampCreatedAt();
        $reservation->stampUpdatedAt();

        return $reservation;
    }

    /**
     * @param list<array{path: string, query: string, body: string}> $requests
     */
    private function buildIndexerAnswering(
        int $status,
        string $responseBody,
        array &$requests,
    ): ReservationIndexer {
        $answerRequest = static function (RequestInterface $request) use (
            $status,
            $responseBody,
            &$requests,
        ): ResponseInterface {
            $requests[] = [
                'path' => $request->getUri()->getPath(),
                'query' => $request->getUri()->getQuery(),
                'body' => strval($request->getBody()),
            ];

            return new Response(
                $status,
                [
                    'Content-Type' => 'application/json',
                    Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
                ],
                $responseBody,
            );
        };

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback($answerRequest);

        return new ReservationIndexer(
            ClientBuilder::create()->setHttpClient($httpClient)->build(),
            new ReservationDocumentFactory(),
            new IndexNameFactory(''),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function readBulkOperations(string $body): array
    {
        $operations = [];
        foreach (explode("\n", trim($body)) as $line) {
            $operations[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return $operations;
    }

    public function testIndexingWaitsUntilTheReservationIsSearchable(): void
    {
        $requests = [];

        $this->buildIndexerAnswering(201, '{"result":"created"}', $requests)->index($this->createReservation());

        self::assertCount(1, $requests);
        parse_str($requests[0]['query'], $requestQuery);
        self::assertSame('wait_for', $requestQuery['refresh'] ?? null);
    }

    public function testTheDocumentIsWrittenToTheReservationsIndexUnderTheReservationsOwnId(): void
    {
        $requests = [];
        $reservation = $this->createReservation();

        $this->buildIndexerAnswering(201, '{"result":"created"}', $requests)->index($reservation);

        self::assertCount(1, $requests);
        self::assertSame('/reservations/_doc/' . $reservation->getId()->toBase32(), $requests[0]['path']);
        self::assertJsonStringEqualsJsonString(
            json_encode(new ReservationDocumentFactory()->build($reservation), JSON_THROW_ON_ERROR),
            $requests[0]['body'],
        );
    }

    /**
     * Two ids, so one operation per reservation and one for the first cannot both fit.
     */
    public function testDeletingDoesNotWaitForTheIndexToCatchUp(): void
    {
        $requests = [];
        $firstReservationId = new Ulid();
        $secondReservationId = new Ulid();

        $this->buildIndexerAnswering(200, '{"errors":false}', $requests)
            ->deleteByIds([$firstReservationId, $secondReservationId]);

        self::assertCount(1, $requests);
        self::assertSame('/_bulk', $requests[0]['path']);
        self::assertSame('', $requests[0]['query']);
        self::assertSame(
            [
                ['delete' => ['_index' => 'reservations', '_id' => $firstReservationId->toBase32()]],
                ['delete' => ['_index' => 'reservations', '_id' => $secondReservationId->toBase32()]],
            ],
            $this->readBulkOperations($requests[0]['body']),
        );
    }

    public function testDeletingNothingAsksTheClusterNothing(): void
    {
        $requests = [];

        $this->buildIndexerAnswering(200, '{"errors":false}', $requests)->deleteByIds([]);

        self::assertSame([], $requests);
    }

    public function testADeleteRefusedForOneDocumentIsAFailure(): void
    {
        $requests = [];
        $reservationId = new Ulid();
        $indexer = $this->buildIndexerAnswering(
            200,
            '{"errors":true,"items":[{"delete":{"_id":"' . $reservationId->toBase32() . '",'
                . '"error":{"type":"index_not_found_exception"}}}]}',
            $requests,
        );

        $this->expectException(BulkIndexingFailedException::class);

        $indexer->deleteByIds([$reservationId]);
    }
}
