<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\DTO\SearchCursor;
use App\Elasticsearch\DocumentFactory\ReservationDocumentFactory;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\Mapping\ReservationIndexDefinition;
use App\Entity\Guest;
use App\Entity\Hotel;
use App\Entity\Reservation;
use App\Entity\ReservationRoom;
use App\Enum\IndexAlias;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Enum\RoomKind;
use App\Repository\DeletedReservationRepository;
use App\Repository\ReservationRepository;
use App\Tests\ApiTestCase;
use App\Tests\ElasticsearchIndexTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

use function array_keys;
use function count;
use function sprintf;
use function urlencode;

/**
 * GET /api/reservations
 */
final class ReservationSearchApiTest extends ApiTestCase
{
    use ElasticsearchIndexTrait;

    private const array INDEXED_RESERVATIONS = [
        ['arrival' => '2026-01-05', 'status' => ReservationStatus::CONFIRMED],
        ['arrival' => '2026-02-05', 'status' => ReservationStatus::PENDING],
        ['arrival' => '2026-03-05', 'status' => ReservationStatus::CANCELLED],
    ];

    private ?string $indexNameToDrop = null;

    protected function tearDown(): void
    {
        if ($this->indexNameToDrop !== null) {
            self::dropIndices($this->indexNameToDrop);
        }

        parent::tearDown();
    }

    private function createIndexWithReservations(): void
    {
        self::connectToElasticsearch();

        $indexNameFactory = self::getContainer()->get(IndexNameFactory::class);
        self::assertInstanceOf(IndexNameFactory::class, $indexNameFactory);
        $indexName = $indexNameFactory->build(IndexAlias::RESERVATIONS);

        self::dropIndices($indexName);
        self::createIndex($indexName, new ReservationIndexDefinition(
            self::createStub(ReservationRepository::class),
            self::createStub(DeletedReservationRepository::class),
            new ReservationDocumentFactory(),
            self::createStub(EntityManagerInterface::class),
        ));
        $this->indexNameToDrop = $indexName;

        $hotel = new Hotel()
            ->setCode('H-0001')
            ->setName('Test Hotel')
            ->setChain('Test Chain')
            ->setCity('Test City');

        $reservationDocumentFactory = new ReservationDocumentFactory();
        $operations = [];
        foreach (self::INDEXED_RESERVATIONS as $reservationIndex => $indexedReservation) {
            $reservation = $this->createReservation(
                $hotel,
                $indexedReservation['arrival'],
                $indexedReservation['status'],
                $reservationIndex,
            );
            $operations[] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => $reservation->getId()->toBase32(),
                ],
            ];
            $operations[] = $reservationDocumentFactory->build($reservation);
        }

        self::indexDocuments($operations);
    }

    private function createReservation(
        Hotel $hotel,
        string $arrival,
        ReservationStatus $status,
        int $reservationIndex,
    ): Reservation {
        $guest = new Guest()
            ->setName(sprintf('Test Guest %d', $reservationIndex))
            ->setEmail(sprintf('test%d@test.com', $reservationIndex))
            ->setPhone('+00000000');

        $reservation = new Reservation();
        $reservation
            ->setNumber(sprintf('2026-%06d', $reservationIndex + 1))
            ->setGuest($guest)
            ->setHotel($hotel)
            ->setStatus($status)
            ->setSource(ReservationSource::WEB)
            ->setArrival(new DateTimeImmutable($arrival))
            ->setDeparture(new DateTimeImmutable($arrival . ' +3 days'))
            ->setTotalPrice(250000)
            ->setPaid(true)
            ->setNote(null)
            ->addRoom(new ReservationRoom()->setKind(RoomKind::DOUBLE)->setGuestCount(2)->setPrice(250000));

        $reservation->stampCreatedAt();
        $reservation->stampUpdatedAt();

        return $reservation;
    }

    public function testAnUnknownStatusListsTheAllowedValues(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/reservations?status%5B%5D=nonexistent-status');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $violations = $this->getResponseBody($client)['violations'];
        self::assertIsArray($violations);
        self::assertIsArray($violations[0]);
        self::assertIsString($violations[0]['title']);
        self::assertStringContainsString('Allowed values: confirmed', $violations[0]['title']);
        self::assertIsArray($violations[0]['parameters']);
        self::assertArrayNotHasKey('hint', $violations[0]['parameters']);
    }

    public function testAHotelThatIsNotAnIdentifierIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/reservations?hotel%5B%5D=not-an-identifier');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $violations = $this->getResponseBody($client)['violations'];
        self::assertIsArray($violations);
        self::assertIsArray($violations[0]);
        self::assertSame('This value is not a valid identifier.', $violations[0]['title']);
    }

    public function testAPageSizeAboveTheLimitIsRejectedAsUnprocessableAndNotAsNotFound(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/reservations?size=101');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame(['size'], $this->getViolatedFields($client));
    }

    public function testAnArrivalRangeThatEndsBeforeItStartsNamesTheFieldItRejects(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/reservations?arrivalFrom=2026-06-01&arrivalTo=2026-01-01');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame(['arrivalTo'], $this->getViolatedFields($client));
        self::assertArrayHasKey('detail', $this->getResponseBody($client));
    }

    public function testPagingPastTheResultWindowIsUnprocessableAndNotAnOutage(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/reservations?size=20&cursor=' . urlencode(
            SearchCursor::createForOffset(ReservationIndexDefinition::MAX_RESULT_WINDOW)->encode(),
        ));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
    }

    public function testAnInvalidCursorCarriesItsProblemType(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/reservations?cursor=garbage%21%21');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame(422, $this->getResponseBody($client)['status']);
        self::assertSame('urn:reservation-search:invalid-cursor', $this->getResponseBody($client)['type']);
        self::assertSame('Invalid pagination cursor.', $this->getResponseBody($client)['detail']);
    }

    public function testASuccessfulSearchCarriesTheWholeBodyTheClientConsumes(): void
    {
        $client = self::createClient();
        $this->createIndexWithReservations();

        $client->request('GET', '/api/reservations?sort=arrival');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $body = $this->getResponseBody($client);
        self::assertSame(['total', 'tookMs', 'items', 'nextCursor', 'facets'], array_keys($body));
        self::assertSame(count(self::INDEXED_RESERVATIONS), $body['total']);
        self::assertIsArray($body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame(ReservationStatus::CONFIRMED->value, $body['items'][0]['status']);
        self::assertIsArray($body['facets']);
        self::assertIsArray($body['facets']['roomKind']);
        self::assertSame(['buckets', 'omittedReservationCount'], array_keys($body['facets']['roomKind']));
        self::assertSame(0, $body['facets']['roomKind']['omittedReservationCount']);

        $roomKindBuckets = $body['facets']['roomKind']['buckets'];
        self::assertIsArray($roomKindBuckets);
        self::assertSame(
            [
                'value' => RoomKind::DOUBLE->value,
                'count' => count(self::INDEXED_RESERVATIONS),
                'label' => null,
                'code' => null,
            ],
            $roomKindBuckets[0],
        );
    }

    public function testTheCursorOfTheFirstPageSurvivesTheQueryStringAndOpensTheSecond(): void
    {
        $client = self::createClient();
        $this->createIndexWithReservations();

        $client->request('GET', '/api/reservations?sort=arrival&size=1');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $firstPage = $this->getResponseBody($client);
        self::assertIsArray($firstPage['items']);
        self::assertIsArray($firstPage['items'][0]);
        self::assertIsString($firstPage['nextCursor']);

        $client->request('GET', '/api/reservations?sort=arrival&size=1&cursor=' . urlencode($firstPage['nextCursor']));

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $secondPage = $this->getResponseBody($client);
        self::assertIsArray($secondPage['items']);
        self::assertIsArray($secondPage['items'][0]);
        self::assertNotSame($firstPage['items'][0]['id'], $secondPage['items'][0]['id']);
    }
}
