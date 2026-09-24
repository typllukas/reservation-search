<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\DTO\ReservationSearchInput;
use App\DTO\SearchCursor;
use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\Mapping\ReservationIndexDefinition;
use App\Elasticsearch\ReservationFacetQueryFactory;
use App\Elasticsearch\ReservationSearchQueryFactory;
use App\Enum\ReservationSort;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Enum\RoomKind;
use App\Exception\InvalidSearchCursorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function end;
use function is_array;
use function sprintf;

/**
 * @see ReservationSearchQueryFactory
 */
final class ReservationSearchQueryFactoryTest extends TestCase
{
    private ReservationSearchQueryFactory $reservationSearchQueryFactory;

    protected function setUp(): void
    {
        $this->reservationSearchQueryFactory = new ReservationSearchQueryFactory(new ReservationFacetQueryFactory());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function getValueAt(array $body, string|int ...$path): mixed
    {
        $value = $body;
        foreach ($path as $step) {
            self::assertIsArray($value);
            self::assertArrayHasKey($step, $value);
            $value = $value[$step];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function getArrayAt(array $body, string|int ...$path): array
    {
        $value = $this->getValueAt($body, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function getShouldClause(array $body, string $queryType, ?string $field): array
    {
        foreach ($this->getArrayAt($body, 'query', 'bool', 'should') as $clause) {
            self::assertIsArray($clause);
            if (!array_key_exists($queryType, $clause)) {
                continue;
            }

            $options = $clause[$queryType];
            self::assertIsArray($options);
            if ($field === null) {
                return $options;
            }

            if (array_key_exists($field, $options)) {
                $fieldOptions = $options[$field];
                self::assertIsArray($fieldOptions);

                return $fieldOptions;
            }
        }

        self::fail(sprintf('The should clause %s over field %s is missing.', $queryType, $field ?? '(none)'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function countShouldClauses(array $body, string $field): int
    {
        return count(array_filter(
            $this->getArrayAt($body, 'query', 'bool', 'should'),
            static fn (mixed $clause): bool => is_array($clause)
                && is_array($clause['prefix'] ?? null)
                && array_key_exists($field, $clause['prefix']),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function readPhonePrefixValues(string $text): array
    {
        $clauses = $this->getArrayAt(
            $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: $text)),
            'query',
            'bool',
            'should',
        );

        $values = [];
        foreach ($clauses as $clause) {
            if (!is_array($clause) || !is_array($clause['prefix'] ?? null)) {
                continue;
            }

            $options = $clause['prefix']['guest.phone_digits'] ?? null;
            if (!is_array($options)) {
                continue;
            }

            self::assertIsString($options['value']);
            $values[] = $options['value'];
        }

        return $values;
    }

    public function testEmptyFilterAsksForEverything(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(size: 37));

        self::assertSame(37, $this->getValueAt($body, 'size'));
        self::assertArrayHasKey('match_all', $this->getArrayAt($body, 'query', 'bool', 'must'));
        self::assertArrayNotHasKey('filter', $this->getArrayAt($body, 'query', 'bool'));
    }

    public function testTheTotalIsCountedToTheEndInsteadOfStoppingAtTheWindow(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'test'));

        self::assertTrue($this->getValueAt($body, 'track_total_hits'));
    }

    public function testTextQueryBoostsGuestNameAboveHotelName(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'test'));

        self::assertSame(3, $this->getShouldClause($body, 'match', 'guest.name')['boost']);
        self::assertSame(
            ['hotel.name^2', 'note', 'note.english'],
            $this->getShouldClause($body, 'multi_match', null)['fields'],
        );
    }

    public function testOnlyGuestNameToleratesTypos(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'test'));

        self::assertSame('AUTO', $this->getShouldClause($body, 'match', 'guest.name')['fuzziness']);
        self::assertArrayNotHasKey('fuzziness', $this->getShouldClause($body, 'multi_match', null));
    }

    public function testUnfinishedNamesAreMatchedByPrefixBelowWholeTermMatches(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'test'));

        self::assertLessThan(
            $this->getShouldClause($body, 'match', 'guest.name')['boost'],
            $this->getShouldClause($body, 'match_bool_prefix', 'guest.name')['boost'],
        );
        self::assertSame(1, $this->getShouldClause($body, 'match_bool_prefix', 'hotel.name')['boost']);

        $prefixedFields = [];
        foreach ($this->getArrayAt($body, 'query', 'bool', 'should') as $clause) {
            self::assertIsArray($clause);
            if (!array_key_exists('match_bool_prefix', $clause)) {
                continue;
            }

            $options = $clause['match_bool_prefix'];
            self::assertIsArray($options);
            $prefixedFields = [...$prefixedFields, ...array_keys($options)];
        }

        self::assertSame(['guest.name', 'hotel.name'], $prefixedFields);
    }

    public function testEmailIsSearchedByPrefixAndNotThroughFulltext(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'jan.novak'));

        self::assertSame('jan.novak', $this->getShouldClause($body, 'prefix', 'guest.email')['value']);
        // guest.email is a keyword, so the prefix is case-sensitive without this
        self::assertTrue($this->getShouldClause($body, 'prefix', 'guest.email')['case_insensitive']);
        // whole list: a contains check would miss a boosted guest.email^2
        self::assertSame(
            ['hotel.name^2', 'note', 'note.english'],
            $this->getShouldClause($body, 'multi_match', null)['fields'],
        );
    }

    public function testReservationNumberIsMatchedExactlyAndByPrefixButNeverFuzzily(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: '2026-000002'));

        $exactNumberClause = $this->getShouldClause($body, 'constant_score', null);
        self::assertSame(['term' => ['number' => '2026-000002']], $exactNumberClause['filter']);
        self::assertSame(10, $exactNumberClause['boost']);
        self::assertSame('2026-000002', $this->getShouldClause($body, 'prefix', 'number')['value']);
        self::assertSame(
            ['hotel.name^2', 'note', 'note.english'],
            $this->getShouldClause($body, 'multi_match', null)['fields'],
        );
    }

    /**
     * A prefix branch sits in 'should', so a short one adds documents rather than ranking them
     */
    public function testPhoneBranchesAreAddedOnlyWhenTheQueryCarriesEnoughDigits(): void
    {
        $withoutDigits = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'testing'));
        $withTooFewDigits = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: '+420 11'));
        $withEnoughDigits = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: '+420 111'));

        self::assertSame(0, $this->countShouldClauses($withoutDigits, 'guest.phone_digits'));
        self::assertSame(0, $this->countShouldClauses($withTooFewDigits, 'guest.phone_digits'));
        self::assertSame(2, $this->countShouldClauses($withEnoughDigits, 'guest.phone_digits'));
        self::assertCount(
            count($this->getArrayAt($withoutDigits, 'query', 'bool', 'should')) + 2,
            $this->getArrayAt($withEnoughDigits, 'query', 'bool', 'should'),
        );
    }

    public function testTheNumberPrefixBranchIsAddedOnlyForAQueryShapedLikeANumber(): void
    {
        foreach (['2026-', '2026', 'testing', '111222333', 'H-0001', '2026-0 novak'] as $textOfAnotherShape) {
            $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: $textOfAnotherShape));

            self::assertSame(0, $this->countShouldClauses($body, 'number'), $textOfAnotherShape);
        }

        $shapedLikeANumber = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: '2026-0'));

        self::assertSame(1, $this->countShouldClauses($shapedLikeANumber, 'number'));
        self::assertSame('2026-0', $this->getShouldClause($shapedLikeANumber, 'prefix', 'number')['value']);
    }

    public function testAQuerySurroundedByWhitespaceStillReachesTheKeywordBranches(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: " \t2026-000001\n"));

        self::assertSame(1, $this->countShouldClauses($body, 'number'));
        self::assertSame('2026-000001', $this->getShouldClause($body, 'prefix', 'number')['value']);
        self::assertSame(
            ['term' => ['number' => '2026-000001']],
            $this->getShouldClause($body, 'constant_score', null)['filter'],
        );
    }

    /**
     * The exact term costs one keyword lookup whatever the shape, so it stays on where the prefix drops out.
     */
    public function testTheExactNumberTermSurvivesAQueryThatDropsThePrefix(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: '2026-'));

        $exactNumberClause = $this->getShouldClause($body, 'constant_score', null);

        self::assertSame(['term' => ['number' => '2026-']], $exactNumberClause['filter']);
    }

    public function testThePhoneBranchesSearchTheTypedFormAndTheOneWithTheCountryCode(): void
    {
        self::assertSame(['111222333', '420111222333'], $this->readPhonePrefixValues('111 222 333'));
    }

    public function testAQueryThatAlreadyLooksPrefixedStillSearchesBothForms(): void
    {
        self::assertSame(['420111', '420420111'], $this->readPhonePrefixValues('420111'));
    }

    public function testTheFirstPageAsksForNoPositionAtAll(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput());

        self::assertArrayNotHasKey('search_after', $body);
        self::assertArrayNotHasKey('from', $body);
    }

    /**
     * Elasticsearch rejects a non-zero from in a body that also carries search_after.
     */
    public function testSortsWithStoredValuesPageByCursorAndRelevancePagesByOffset(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            sort: ReservationSort::ARRIVAL,
            cursor: SearchCursor::createForSortValues(ReservationSort::ARRIVAL, [1757808000000, '01M0YXP64A'])
                ->encode(),
        ));

        self::assertSame([1757808000000, '01M0YXP64A'], $this->getArrayAt($body, 'search_after'));
        self::assertArrayNotHasKey('from', $body);

        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            cursor: SearchCursor::createForOffset(20)->encode(),
        ));

        self::assertSame(20, $this->getValueAt($body, 'from'));
        self::assertArrayNotHasKey('search_after', $body);
    }

    public function testACursorFromAnotherSortOrderIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            sort: ReservationSort::LAST_CHANGE,
            cursor: SearchCursor::createForSortValues(ReservationSort::ARRIVAL, [1757808000000, '01M0YXP64A'])
                ->encode(),
        ));
    }

    /**
     * Without this the value reaches search_after, and the 400 it answers reaches the desk as a 503.
     *
     * @param non-empty-list<mixed> $tamperedSortValues
     */
    #[DataProvider('provideTamperedSortValues')]
    public function testACursorCarryingASortValueOfTheWrongTypeIsRejected(array $tamperedSortValues): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            sort: ReservationSort::ARRIVAL,
            cursor: SearchCursor::createForSortValues(ReservationSort::ARRIVAL, $tamperedSortValues)->encode(),
        ));
    }

    /**
     * @return iterable<string, array{non-empty-list<mixed>}>
     */
    public static function provideTamperedSortValues(): iterable
    {
        yield 'a string where the date belongs' => [['banana', '01M0YXP64A']];
        yield 'a boolean where the date belongs' => [[true, '01M0YXP64A']];
        yield 'the two values swapped' => [['01M0YXP64A', 1757808000000]];
        yield 'a float where the date belongs' => [[1757808000000.5, '01M0YXP64A']];
    }

    public function testACursorOfTheWrongVariantForTheSortIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            sort: ReservationSort::ARRIVAL,
            cursor: SearchCursor::createForOffset(20)->encode(),
        ));
    }

    public function testPagingPastTheResultWindowIsRejectedByTheApplication(): void
    {
        $this->expectException(ResultWindowExceededException::class);

        $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            size: 20,
            cursor: SearchCursor::createForOffset(ReservationIndexDefinition::MAX_RESULT_WINDOW)->encode(),
        ));
    }

    /**
     * Elasticsearch allows from plus size to equal the window.
     */
    public function testTheLastPageInsideTheResultWindowIsStillBuilt(): void
    {
        $lastOffset = ReservationIndexDefinition::MAX_RESULT_WINDOW - 20;

        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            size: 20,
            cursor: SearchCursor::createForOffset($lastOffset)->encode(),
        ));

        self::assertSame($lastOffset, $this->getValueAt($body, 'from'));
    }

    public function testMalformedCursorIsRejected(): void
    {
        $this->expectException(InvalidSearchCursorException::class);

        $this->reservationSearchQueryFactory->build(new ReservationSearchInput(cursor: 'nonsense!!'));
    }

    /**
     * A plain terms over rooms.kind silently finds nothing.
     */
    public function testRoomKindIsFilteredThroughANestedQueryInThePostFilter(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(roomKind: [RoomKind::DOUBLE]));

        self::assertArrayNotHasKey('filter', $this->getArrayAt($body, 'query', 'bool'));
        self::assertSame(
            'rooms',
            $this->getValueAt($body, 'post_filter', 'bool', 'filter', 0, 'nested', 'path'),
        );
        self::assertSame(
            [RoomKind::DOUBLE->value],
            $this->getArrayAt($body, 'post_filter', 'bool', 'filter', 0, 'nested', 'query', 'terms', 'rooms.kind'),
        );
    }

    public function testTheRoomKindFacetCountsParentsThroughReverseNested(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput());

        self::assertSame(
            'rooms',
            $this->getValueAt($body, 'aggs', 'roomKind', 'aggs', 'roomKind', 'nested', 'path'),
        );
        self::assertSame(
            'rooms.kind',
            $this->getValueAt($body, 'aggs', 'roomKind', 'aggs', 'roomKind', 'aggs', 'roomKind', 'terms', 'field'),
        );
        self::assertArrayHasKey(
            'reverse_nested',
            $this->getArrayAt(
                $body,
                'aggs',
                'roomKind',
                'aggs',
                'roomKind',
                'aggs',
                'roomKind',
                'aggs',
                ReservationFacetQueryFactory::PARENT_COUNT_AGGREGATION,
            ),
        );
    }

    public function testTheRoomKindFilterIsExcludedFromItsOwnFacetOnly(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(roomKind: [RoomKind::DOUBLE]));

        self::assertArrayHasKey('match_all', $this->getArrayAt($body, 'aggs', 'roomKind', 'filter'));
        self::assertSame(
            'rooms',
            $this->getValueAt($body, 'aggs', 'status', 'filter', 'bool', 'filter', 0, 'nested', 'path'),
        );
    }

    public function testRangesStayInTheQueryBecauseTheyHaveNoFacet(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            arrivalFrom: '2026-01-01',
            priceTo: 500000,
        ));

        $queryFilters = $this->getArrayAt($body, 'query', 'bool', 'filter');
        self::assertCount(2, $queryFilters);
        self::assertSame(
            ['gte' => '2026-01-01'],
            $this->getArrayAt($body, 'query', 'bool', 'filter', 0, 'range', 'arrival'),
        );
        self::assertSame(
            ['lte' => 500000],
            $this->getArrayAt($body, 'query', 'bool', 'filter', 1, 'range', 'total_price'),
        );
        self::assertArrayNotHasKey('post_filter', $body);
    }

    public function testFacetedDimensionsGoIntoPostFilterAndNotIntoTheQuery(): void
    {
        $hotelId = new Ulid();
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            status: [ReservationStatus::CONFIRMED],
            hotel: [$hotelId],
        ));

        self::assertArrayNotHasKey('filter', $this->getArrayAt($body, 'query', 'bool'));
        self::assertSame(
            [ReservationStatus::CONFIRMED->value],
            $this->getArrayAt($body, 'post_filter', 'bool', 'filter', 0, 'terms', 'status'),
        );
        self::assertSame(
            [$hotelId->toBase32()],
            $this->getArrayAt($body, 'post_filter', 'bool', 'filter', 1, 'terms', 'hotel.id'),
        );
    }

    public function testEachFacetIsCountedWithoutItsOwnFilterButWithTheOthers(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(
            status: [ReservationStatus::CONFIRMED],
            source: [ReservationSource::TRAVEL_AGENCY],
        ));

        $statusFilter = $this->getArrayAt($body, 'aggs', 'status', 'filter', 'bool', 'filter');
        self::assertCount(1, $statusFilter);
        self::assertSame(
            [ReservationSource::TRAVEL_AGENCY->value],
            $this->getArrayAt($body, 'aggs', 'status', 'filter', 'bool', 'filter', 0, 'terms', 'source'),
        );

        self::assertSame(
            [ReservationStatus::CONFIRMED->value],
            $this->getArrayAt($body, 'aggs', 'source', 'filter', 'bool', 'filter', 0, 'terms', 'status'),
        );

        self::assertCount(2, $this->getArrayAt($body, 'aggs', 'hotel', 'filter', 'bool', 'filter'));
    }

    public function testTheHotelFacetCarriesALabelBecauseItsKeyIsAUlid(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput());

        self::assertSame(
            'hotel.name.keyword',
            $this->getValueAt($body, 'aggs', 'hotel', 'aggs', 'hotel', 'aggs', 'label', 'terms', 'field'),
        );
        self::assertArrayNotHasKey('aggs', $this->getArrayAt($body, 'aggs', 'status', 'aggs', 'status'));
    }

    /**
     * id rather than _id: fielddata is disabled on _id and the query would end in an error.
     */
    public function testEverySortEndsWithTheIdTiebreaker(): void
    {
        foreach (ReservationSort::cases() as $sort) {
            $sortClauses = $this->getArrayAt(
                $this->reservationSearchQueryFactory->build(new ReservationSearchInput(sort: $sort)),
                'sort',
            );

            self::assertSame(['id' => 'asc'], end($sortClauses));
        }
    }

    public function testHighlightWrapsMatchesInMarkTags(): void
    {
        $body = $this->reservationSearchQueryFactory->build(new ReservationSearchInput(text: 'test'));

        self::assertSame(['<mark>'], $this->getArrayAt($body, 'highlight', 'pre_tags'));
        self::assertSame(['</mark>'], $this->getArrayAt($body, 'highlight', 'post_tags'));
        self::assertSame(
            ['guest.name', 'hotel.name', 'note', 'note.english'],
            array_keys($this->getArrayAt($body, 'highlight', 'fields')),
        );
    }
}
