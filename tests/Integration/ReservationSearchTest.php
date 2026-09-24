<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\ReservationHit;
use App\DTO\ReservationSearchInput;
use App\DTO\ReservationSearchResult;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DataTransformer\ArrayToReservationScoreExplanation;
use App\Elasticsearch\DataTransformer\ArrayToReservationSearchResult;
use App\Elasticsearch\DocumentFactory\ReservationDocumentFactory;
use App\Elasticsearch\Mapping\ReservationIndexDefinition;
use App\Elasticsearch\ReservationFacetQueryFactory;
use App\Elasticsearch\ReservationSearchQueryFactory;
use App\Entity\Guest;
use App\Entity\Hotel;
use App\Entity\Reservation;
use App\Entity\ReservationRoom;
use App\Enum\ReservationSort;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Enum\RoomKind;
use App\Helper\MixedToFloat;
use App\Helper\MixedToString;
use App\Repository\DeletedReservationRepository;
use App\Repository\ReservationRepository;
use App\Tests\ElasticsearchTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Symfony\Component\Uid\Ulid;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function sort;
use function sprintf;
use function strval;

final class ReservationSearchTest extends ElasticsearchTestCase
{
    private const string TEST_INDEX_NAME = 'reservations_integration_test';

    // own index: if dynamic strict ever stops refusing, the probe lands in the fixtures and adds to the counts
    private const string PROBE_INDEX_NAME = 'reservations_integration_test_probe';

    private const array INDEXED_RESERVATIONS = [
        [
            'name' => 'Novák Jan',
            'status' => ReservationStatus::CONFIRMED,
            'source' => ReservationSource::TRAVEL_AGENCY,
            'note' => 'Pozdní příjezd.',
            'roomKinds' => [RoomKind::DOUBLE],
        ],
        [
            'name' => 'Nováková Petra',
            'status' => ReservationStatus::CONFIRMED,
            'source' => ReservationSource::WEB,
            'note' => 'Alergie na peří.',
            'roomKinds' => [RoomKind::SINGLE],
        ],
        [
            'name' => 'Nováková Jana',
            'status' => ReservationStatus::PENDING,
            'source' => ReservationSource::WEB,
            'note' => 'Late arrival, guest asked about the reservations for next year.',
            'roomKinds' => [RoomKind::APARTMENT],
        ],
        [
            'name' => 'Novotný Jan',
            'status' => ReservationStatus::CONFIRMED,
            'source' => ReservationSource::PHONE,
            'note' => 'Potvrzeno telefonicky.',
            'roomKinds' => [RoomKind::DOUBLE],
        ],
        [
            'name' => 'Svoboda Marek',
            'status' => ReservationStatus::CANCELLED,
            'source' => ReservationSource::PHONE,
            'note' => 'Nováková volala kvůli rezervacím.',
            'roomKinds' => [RoomKind::DOUBLE],
        ],
        [
            'name' => 'Novák Jan',
            'status' => ReservationStatus::CONFIRMED,
            'source' => ReservationSource::TRAVEL_AGENCY,
            'note' => 'Firemní akce.',
            'roomKinds' => [RoomKind::DOUBLE, RoomKind::DOUBLE],
        ],
    ];

    public static function setUpBeforeClass(): void
    {
        self::connectToElasticsearch();
        self::dropIndices(self::TEST_INDEX_NAME, self::PROBE_INDEX_NAME);

        $indexDefinition = self::createIndexDefinition();
        self::createIndex(self::TEST_INDEX_NAME, $indexDefinition);
        self::createIndex(self::PROBE_INDEX_NAME, $indexDefinition);

        self::indexFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        self::dropIndices(self::TEST_INDEX_NAME, self::PROBE_INDEX_NAME);
    }

    private static function createIndexDefinition(): ReservationIndexDefinition
    {
        return new ReservationIndexDefinition(
            self::createStub(ReservationRepository::class),
            self::createStub(DeletedReservationRepository::class),
            new ReservationDocumentFactory(),
            self::createStub(EntityManagerInterface::class),
        );
    }

    private static function indexFixtures(): void
    {
        $hotel = new Hotel()
            ->setCode('H-0001')
            ->setName('Hotel Vltava')
            ->setChain('Vltava Group')
            ->setCity('Praha');

        $reservationDocumentFactory = new ReservationDocumentFactory();
        $operations = [];
        foreach (self::INDEXED_RESERVATIONS as $index => $indexedReservation) {
            $reservation = self::createReservation(
                $hotel,
                $indexedReservation['name'],
                $indexedReservation['status'],
                $indexedReservation['source'],
                $indexedReservation['note'],
                $index,
                $indexedReservation['roomKinds'],
            );
            $operations[] = [
                'index' => [
                    '_index' => self::TEST_INDEX_NAME,
                    '_id' => $reservation->getId()->toBase32(),
                ],
            ];
            $operations[] = $reservationDocumentFactory->build($reservation);
        }

        self::indexDocuments($operations);
    }

    /**
     * @param array<int, RoomKind> $roomKinds
     */
    private static function createReservation(
        Hotel $hotel,
        string $guestName,
        ReservationStatus $status,
        ReservationSource $source,
        ?string $note,
        int $index,
        array $roomKinds,
    ): Reservation {
        $guest = new Guest()
            ->setName($guestName)
            ->setEmail(sprintf('test%d@test.com', $index))
            ->setPhone('+420 111 222 333');

        $reservation = new Reservation();
        $reservation
            ->setNumber(sprintf('2026-%06d', $index + 1))
            ->setGuest($guest)
            ->setHotel($hotel)
            ->setStatus($status)
            ->setSource($source)
            ->setArrival(new DateTimeImmutable('2026-09-14'))
            ->setDeparture(new DateTimeImmutable('2026-09-17'))
            ->setTotalPrice(250000 * count($roomKinds))
            ->setPaid(true)
            ->setNote($note);

        foreach ($roomKinds as $roomKind) {
            $reservation->addRoom(new ReservationRoom()->setKind($roomKind)->setGuestCount(2)->setPrice(250000));
        }

        $reservation->stampCreatedAt();
        $reservation->stampUpdatedAt();

        return $reservation;
    }

    private function countIndexedReservationsWithStatus(ReservationStatus $status): int
    {
        return count(array_filter(
            self::INDEXED_RESERVATIONS,
            static fn (array $indexedReservation): bool => $indexedReservation['status'] === $status,
        ));
    }

    private function countIndexedReservationsWithRoomKind(RoomKind $roomKind): int
    {
        return count(array_filter(
            self::INDEXED_RESERVATIONS,
            static fn (array $indexedReservation): bool => in_array($roomKind, $indexedReservation['roomKinds'], true),
        ));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function search(array $body): array
    {
        return ResponseBody::read(self::$elasticsearchClient->search([
            'index' => self::TEST_INDEX_NAME,
            'body' => $body,
        ]));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    private function explain(string $documentId, array $query): array
    {
        return ResponseBody::read(self::$elasticsearchClient->explain([
            'index' => self::TEST_INDEX_NAME,
            'id' => $documentId,
            'body' => ['query' => $query],
        ]));
    }

    /**
     * @param array<array-key, mixed> $response
     *
     * @return array<array-key, mixed>
     */
    private function getFirstHit(array $response): array
    {
        $hits = array_values(ResponseBody::readArray(
            ResponseBody::readArray($response, 'hits'),
            'hits',
        ));
        self::assertNotSame([], $hits);

        return ResponseBody::narrowToArray($hits[0]);
    }

    /**
     * @param array<array-key, mixed> $response
     *
     * @return array<int, float>
     */
    private function readScores(array $response): array
    {
        $hits = array_values(ResponseBody::readArray(
            ResponseBody::readArray($response, 'hits'),
            'hits',
        ));

        $scores = [];
        foreach ($hits as $hit) {
            $scores[] = MixedToFloat::transformStrict(ResponseBody::narrowToArray($hit)['_score']);
        }

        return $scores;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSearchBody(ReservationSearchInput $input): array
    {
        return new ReservationSearchQueryFactory(new ReservationFacetQueryFactory())->build($input);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScoringQuery(ReservationSearchInput $input): array
    {
        return new ReservationSearchQueryFactory(new ReservationFacetQueryFactory())->buildQuery($input);
    }

    private function runSearch(ReservationSearchInput $input, int $rowsBeforeThisPage): ReservationSearchResult
    {
        return ArrayToReservationSearchResult::transform(
            $this->search($this->buildSearchBody($input)),
            $input->sort,
            $rowsBeforeThisPage,
            $input->size,
        );
    }

    public function testTheExplainedScoreIsTheScoreThatOrderedTheHit(): void
    {
        $input = new ReservationSearchInput(text: 'novak');
        $firstHit = $this->getFirstHit($this->search($this->buildSearchBody($input)));

        $explanation = ArrayToReservationScoreExplanation::transform($this->explain(
            MixedToString::transformStrict($firstHit['_id']),
            $this->buildScoringQuery($input),
        ));

        self::assertTrue($explanation->matched);
        self::assertGreaterThan(0.0, $explanation->explanation->value);
        self::assertEqualsWithDelta(
            MixedToFloat::transformStrict($firstHit['_score']),
            $explanation->explanation->value,
            0.0001,
        );
    }

    /**
     * buildQuery() leaves out post_filter, so a status filter would not change the match.
     */
    public function testADocumentOutsideTheQueryIsReportedAsNotMatched(): void
    {
        $firstHit = $this->getFirstHit(
            $this->search($this->buildSearchBody(new ReservationSearchInput(text: 'novak'))),
        );

        $explanation = ArrayToReservationScoreExplanation::transform($this->explain(
            MixedToString::transformStrict($firstHit['_id']),
            $this->buildScoringQuery(new ReservationSearchInput(text: 'zzzznobodyatall')),
        ));

        self::assertFalse($explanation->matched);
        self::assertSame(0.0, $explanation->explanation->value);
    }

    public function testExplainingADocumentTheIndexDoesNotHaveIsA404(): void
    {
        $this->expectException(ElasticsearchException::class);
        $this->expectExceptionCode(404);

        $unindexedReservationId = new Ulid()->toBase32();

        $this->explain(
            $unindexedReservationId,
            $this->buildScoringQuery(new ReservationSearchInput(text: 'novak')),
        );
    }

    public function testQueryWithoutDiacriticsFindsTheNameWithThem(): void
    {
        $result = $this->runSearch(new ReservationSearchInput(text: 'novak'), 0);

        // Novák Jan is one edit from novak, so fuzziness finds him even without folding
        $names = array_map(static fn (ReservationHit $hit): string => $hit->guest->name, $result->items);
        self::assertContains('Nováková Petra', $names);
    }

    public function testAnEnglishNoteIsFoundAcrossItsWordForms(): void
    {
        $result = $this->runSearch(new ReservationSearchInput(text: 'reservation'), 0);

        self::assertSame(1, $result->total);
        self::assertSame('Nováková Jana', $result->items[0]->guest->name);
        self::assertSame(
            ['Late arrival, guest asked about the <mark>reservations</mark> for next year.'],
            $result->items[0]->highlight['note'],
        );
    }

    /**
     * Still passes with GUEST_NAME_BOOST set to 1, so the boost value is not guarded here.
     */
    public function testGuestNameOutranksAMatchFoundOnlyInTheNote(): void
    {
        $result = $this->runSearch(new ReservationSearchInput(text: 'novak'), 0);

        self::assertNotSame([], $result->items);
        self::assertSame('Novák Jan', $result->items[0]->guest->name);
    }

    public function testAnExactReservationNumberScoresTheTwoNumberBranches(): void
    {
        $body = $this->buildSearchBody(new ReservationSearchInput(text: '2026-000002'));

        $scores = $this->readScores($this->search($body));

        self::assertCount(1, $scores);
        self::assertEqualsWithDelta(
            ReservationSearchQueryFactory::NUMBER_BOOST + ReservationSearchQueryFactory::NUMBER_PREFIX_BOOST,
            $scores[0],
            0.0001,
        );
    }

    public function testAnEmailPrefixScoresItsOwnBoostAlone(): void
    {
        $body = $this->buildSearchBody(new ReservationSearchInput(text: 'test3'));

        $scores = $this->readScores($this->search($body));

        self::assertCount(1, $scores);
        self::assertEqualsWithDelta(ReservationSearchQueryFactory::GUEST_EMAIL_PREFIX_BOOST, $scores[0], 0.0001);
    }

    /**
     * Every fixture guest has the same number, so the whole set scores the phone branch.
     */
    public function testAPhoneQueryWithoutTheDiallingCodeStillScoresThePhoneBranch(): void
    {
        $body = $this->buildSearchBody(new ReservationSearchInput(text: '111222333'));

        $scores = $this->readScores($this->search($body));

        self::assertCount(count(self::INDEXED_RESERVATIONS), $scores);
        foreach ($scores as $score) {
            self::assertEqualsWithDelta(ReservationSearchQueryFactory::GUEST_PHONE_PREFIX_BOOST, $score, 0.0001);
        }
    }

    public function testFilteringOneDimensionDoesNotEmptyItsOwnFacet(): void
    {
        $confirmedReservations = $this->countIndexedReservationsWithStatus(ReservationStatus::CONFIRMED);

        $result = $this->runSearch(new ReservationSearchInput(status: [ReservationStatus::CONFIRMED]), 0);

        self::assertSame($confirmedReservations, $result->total);

        $statusCounts = [];
        foreach ($result->facets['status']->buckets as $bucket) {
            $statusCounts[$bucket->value] = $bucket->count;
        }

        self::assertSame($confirmedReservations, $statusCounts[ReservationStatus::CONFIRMED->value]);
        self::assertArrayHasKey(ReservationStatus::CANCELLED->value, $statusCounts);
        self::assertArrayHasKey(ReservationStatus::PENDING->value, $statusCounts);
    }

    public function testTheRoomKindFacetCountsReservationsAndNotRooms(): void
    {
        $result = $this->runSearch(new ReservationSearchInput(), 0);

        $roomKindCounts = [];
        foreach ($result->facets['roomKind']->buckets as $bucket) {
            $roomKindCounts[$bucket->value] = $bucket->count;
        }

        self::assertNotSame([], $roomKindCounts);
        self::assertSame(
            $this->countIndexedReservationsWithRoomKind(RoomKind::DOUBLE),
            $roomKindCounts[RoomKind::DOUBLE->value],
        );
        self::assertSame(
            $this->countIndexedReservationsWithRoomKind(RoomKind::SINGLE),
            $roomKindCounts[RoomKind::SINGLE->value],
        );
        self::assertSame(
            $this->countIndexedReservationsWithRoomKind(RoomKind::APARTMENT),
            $roomKindCounts[RoomKind::APARTMENT->value],
        );
    }

    public function testFilteringByRoomKindReturnsReservationsWithSuchARoom(): void
    {
        $result = $this->runSearch(new ReservationSearchInput(roomKind: [RoomKind::APARTMENT]), 0);

        self::assertSame($this->countIndexedReservationsWithRoomKind(RoomKind::APARTMENT), $result->total);
        self::assertSame('Nováková Jana', $result->items[0]->guest->name);
    }

    /**
     * All fixtures arrive on the same day, so the sort falls back to the id tiebreaker; that is the
     * order asserted at the end.
     */
    public function testASortedSearchPagesThroughEveryReservationExactlyOnce(): void
    {
        $pageSize = 2;
        $safetyLimit = count(self::INDEXED_RESERVATIONS);
        $cursor = null;
        $pages = 0;
        $pagedIds = [];

        do {
            $result = $this->runSearch(
                new ReservationSearchInput(sort: ReservationSort::ARRIVAL, size: $pageSize, cursor: $cursor),
                0,
            );

            foreach ($result->items as $reservationHit) {
                $pagedIds[] = $reservationHit->id;
            }

            $cursor = $result->nextCursor;
            ++$pages;
        } while ($cursor !== null && $pages <= $safetyLimit);

        self::assertNull($cursor);
        self::assertCount(count(self::INDEXED_RESERVATIONS), $pagedIds);
        self::assertSame(array_values(array_unique($pagedIds)), $pagedIds);

        $ascendingIds = $pagedIds;
        sort($ascendingIds);
        self::assertSame($ascendingIds, $pagedIds);
    }

    public function testTheMappingRefusesAFieldItDoesNotDeclare(): void
    {
        $this->expectException(ElasticsearchException::class);
        $this->expectExceptionMessageMatches('/strict_dynamic_mapping_exception/');

        self::$elasticsearchClient->index([
            'index' => self::PROBE_INDEX_NAME,
            'id' => 'undeclared-field-probe',
            'body' => [
                'number' => '2026-999999',
                'undeclared' => 'x',
            ],
        ]);
    }

    public function testTheIndexCarriesTheResultWindowTheApiRefusesAt(): void
    {
        $settings = ResponseBody::readArray(
            ResponseBody::readArray(
                ResponseBody::read(
                    self::$elasticsearchClient->indices()->getSettings(['index' => self::TEST_INDEX_NAME]),
                ),
                self::TEST_INDEX_NAME,
            ),
            'settings',
        );

        self::assertSame(
            strval(ReservationIndexDefinition::MAX_RESULT_WINDOW),
            ResponseBody::readStringStrict(ResponseBody::readArray($settings, 'index'), 'max_result_window'),
        );
    }
}
