<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch\DataTransformer;

use App\DTO\SearchCursor;
use App\Elasticsearch\DataTransformer\ArrayToReservationSearchResult;
use App\Enum\ReservationSort;
use LogicException;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function count;

/**
 * @see ArrayToReservationSearchResult
 */
final class ArrayToReservationSearchResultTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $hits
     * @param array<string, mixed> $aggregations
     *
     * @return array<string, mixed>
     */
    private function buildResponse(array $hits, array $aggregations): array
    {
        return [
            'took' => 3,
            'hits' => [
                'total' => [
                    'value' => 7,
                    'relation' => 'eq',
                ],
                'hits' => $hits,
            ],
            'aggregations' => $aggregations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHit(): array
    {
        return [
            '_source' => [
                'id' => '01M1CEXXTK566P0E1WFXK6FSPD',
                'number' => '2026-000001',
                'status' => 'confirmed',
                'source' => 'travel_agency',
                'arrival' => '2026-09-14',
                'departure' => '2026-09-17',
                'nights' => 3,
                'total_price' => 250000,
                'paid' => true,
                'note' => null,
                'guest' => [
                    'id' => '01M1CEXXTGKAFBCS4Z5D5VWAGP',
                    'name' => 'Novák Jan',
                    'email' => 'test@test.com',
                    'phone' => '+00000000',
                ],
                'hotel' => [
                    'id' => '01M1CEXXJK9H2MVRXH0TE13WYP',
                    'code' => 'H-0001',
                    'name' => 'Hotel Vltava Praha',
                    'chain' => 'Vltava Group',
                ],
            ],
            'sort' => [1788198451000, '01M1CEXXTQP45ET80728M06BDR'],
        ];
    }

    public function testAFlooredTotalIsRefusedInsteadOfPassedOnAsACount(): void
    {
        $flooredResponse = [
            'took' => 3,
            'hits' => [
                'total' => ['value' => 10000, 'relation' => 'gte'],
                'hits' => [],
            ],
            'aggregations' => [],
        ];

        $this->expectException(LogicException::class);

        ArrayToReservationSearchResult::transform($flooredResponse, ReservationSort::ARRIVAL, 0, 20);
    }

    public function testHighlightKeysUseTheResponseNamesAndNotTheIndexFieldNames(): void
    {
        $hit = $this->buildHit();
        $hit['highlight'] = [
            'guest.name' => ['<mark>Novák</mark> Jan'],
            'note' => ['volala <mark>Nováková</mark>'],
        ];

        $result = ArrayToReservationSearchResult::transform(
            $this->buildResponse([$hit], []),
            ReservationSort::ARRIVAL,
            0,
            1,
        );

        self::assertSame(['guestName', 'note'], array_keys($result->items[0]->highlight));
        self::assertSame(['<mark>Novák</mark> Jan'], $result->items[0]->highlight['guestName']);
    }

    /**
     * note and note.english are analysed differently, so each marks a term the other misses.
     */
    public function testNoteAndItsEnglishSubFieldHighlightAsOneNoteWithoutDuplicates(): void
    {
        $hit = $this->buildHit();
        $hit['highlight'] = [
            'note' => ['about the <mark>reservations</mark>', 'jen česky <mark>rezervaci</mark>'],
            'note.english' => ['about the <mark>reservations</mark>', 'a second <mark>reservation</mark>'],
        ];

        $result = ArrayToReservationSearchResult::transform(
            $this->buildResponse([$hit], []),
            ReservationSort::ARRIVAL,
            0,
            1,
        );

        self::assertSame(
            [
                'about the <mark>reservations</mark>',
                'jen česky <mark>rezervaci</mark>',
                'a second <mark>reservation</mark>',
            ],
            $result->items[0]->highlight['note'],
        );
    }

    /**
     * Two hits, so counting the page and adding one give different answers.
     */
    public function testRelevanceCountsRowsInsteadOfPointingAtTheLastHit(): void
    {
        $hits = [$this->buildHit(), $this->buildHit()];
        $result = ArrayToReservationSearchResult::transform(
            $this->buildResponse($hits, []),
            ReservationSort::RELEVANCE,
            20,
            count($hits),
        );

        self::assertCount(2, $result->items);
        self::assertNotNull($result->nextCursor);
        self::assertSame(20 + count($hits), SearchCursor::decode($result->nextCursor)->offset);
    }

    public function testASortedPageGetsACursorCarryingTheLastHitsSortValues(): void
    {
        $earlierHit = $this->buildHit();
        $earlierHit['sort'] = [1788198450000, '01M1CEXXTQP45ET80728M06BDQ'];

        $result = ArrayToReservationSearchResult::transform(
            $this->buildResponse([$earlierHit, $this->buildHit()], []),
            ReservationSort::ARRIVAL,
            0,
            2,
        );

        self::assertNotNull($result->nextCursor);

        $cursor = SearchCursor::decode($result->nextCursor);
        self::assertSame([1788198451000, '01M1CEXXTQP45ET80728M06BDR'], $cursor->sortValues);
        self::assertNull($cursor->offset);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFacetAggregations(): array
    {
        return [
            'status' => [
                'status' => [
                    'sum_other_doc_count' => 0,
                    'buckets' => [
                        [
                            'key' => 'confirmed',
                            'doc_count' => 12,
                        ],
                    ],
                ],
            ],
            'hotel' => [
                'hotel' => [
                    'sum_other_doc_count' => 906569,
                    'buckets' => [
                        [
                            'key' => '01M1CEXXJK9H2MVRXH0TE13WYP',
                            'doc_count' => 7,
                            'label' => [
                                'buckets' => [
                                    [
                                        'key' => 'Hotel Vltava Praha',
                                        'doc_count' => 7,
                                    ],
                                ],
                            ],
                            'code' => [
                                'buckets' => [
                                    [
                                        'key' => 'H-0001',
                                        'doc_count' => 7,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testTheHotelFacetCarriesALabelAndACodeAndTheStatusFacetCarriesNeither(): void
    {
        $result = ArrayToReservationSearchResult::transform(
            $this->buildResponse([], $this->buildFacetAggregations()),
            ReservationSort::ARRIVAL,
            0,
            20,
        );

        self::assertSame('01M1CEXXJK9H2MVRXH0TE13WYP', $result->facets['hotel']->buckets[0]->value);
        self::assertSame('Hotel Vltava Praha', $result->facets['hotel']->buckets[0]->label);
        self::assertSame('H-0001', $result->facets['hotel']->buckets[0]->code);
        self::assertSame('confirmed', $result->facets['status']->buckets[0]->value);
        self::assertNull($result->facets['status']->buckets[0]->label);
        self::assertNull($result->facets['status']->buckets[0]->code);
    }

    public function testOnlyATruncatedFacetReportsReservationsLeftOut(): void
    {
        $result = ArrayToReservationSearchResult::transform(
            $this->buildResponse([], $this->buildFacetAggregations()),
            ReservationSort::ARRIVAL,
            0,
            20,
        );

        self::assertSame(906569, $result->facets['hotel']->omittedReservationCount);
        self::assertSame(0, $result->facets['status']->omittedReservationCount);
    }

    public function testASortedHitWithoutSortValuesIsAFailure(): void
    {
        $hit = $this->buildHit();
        unset($hit['sort']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('A sorted search returned a hit without sort values.');

        ArrayToReservationSearchResult::transform($this->buildResponse([$hit], []), ReservationSort::ARRIVAL, 0, 1);
    }
}
