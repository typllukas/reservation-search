<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ReservationSearchInput;
use App\DTO\SearchCursor;
use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\Mapping\ReservationIndexDefinition;
use App\Enum\ReservationSort;
use App\Exception\InvalidSearchCursorException;
use App\Helper\PhoneDigits;
use LogicException;
use stdClass;

use function array_fill_keys;
use function array_filter;
use function array_key_first;
use function array_keys;
use function array_values;
use function count;
use function get_debug_type;
use function preg_match;
use function sprintf;
use function strlen;
use function trim;

final readonly class ReservationSearchQueryFactory
{
    /**
     * Fuzziness fits a personal name, where an edit is a typo of the same value; on the keyword
     * fields one edit lands on a different record.
     */
    private const string GUEST_NAME_FIELD = 'guest.name';

    /**
     * hotel.name is boosted over note so a query for a hotel finds the hotel and not the notes that
     * mention it.
     */
    private const array MULTI_MATCH_FIELDS = ['hotel.name^2', 'note', 'note.english'];

    /**
     * A note mentioning Nováková once has a huge idf and beat every real Novák.
     */
    private const int GUEST_NAME_BOOST = 3;

    /**
     * Half of the whole-term boosts, like the number's 10 against 5. No prefix branch over note:
     * a prefix nov would match listopad, novomanželé and novorozenec.
     */
    private const float GUEST_NAME_PREFIX_BOOST = 1.5;

    private const int HOTEL_NAME_PREFIX_BOOST = 1;

    public const int GUEST_EMAIL_PREFIX_BOOST = 2;

    /**
     * Through constant_score, so this is the whole score of the branch. A number exists once, and its
     * idf put the term query below the prefix branch on a small index.
     */
    public const int NUMBER_BOOST = 10;

    public const int NUMBER_PREFIX_BOOST = 5;

    public const int GUEST_PHONE_PREFIX_BOOST = 5;

    /**
     * A second candidate for the search; the guest record keeps the number as it was typed.
     */
    private const string DEFAULT_COUNTRY_CODE = '420';

    /**
     * Stored digits open with a dialling code, so a shorter prefix picks a country and not a person.
     * The branch sits in should under minimum_should_match 1, so it adds documents instead of ranking them.
     */
    private const int MINIMUM_PHONE_DIGITS = 6;

    /**
     * A reservation number is a year, a dash and digits, so a prefix over anything else matches nothing.
     */
    private const string NUMBER_PREFIX_PATTERN = '/^\d{4}-\d+$/';

    /**
     * Index path to response key; the response reader needs the same list.
     */
    public const array HIGHLIGHT_FIELDS = [
        'guest.name' => 'guestName',
        'hotel.name' => 'hotelName',
        'note' => 'note',
        'note.english' => 'note',
    ];

    public function __construct(
        private ReservationFacetQueryFactory $reservationFacetQueryFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidSearchCursorException
     * @throws ResultWindowExceededException
     */
    public function build(ReservationSearchInput $input): array
    {
        $body = [
            'size' => $input->size,
            // without this the total stops at 10,000 and reports gte, while the facets count the real matches
            'track_total_hits' => true,
            'query' => $this->buildQuery($input),
            'sort' => $this->buildSortClauses($input->sort),
            'highlight' => [
                // the default is <em>, and <mark> is what HTML has for a search hit
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                // an empty PHP array encodes as [], Elasticsearch needs {} here
                'fields' => array_fill_keys(array_keys(self::HIGHLIGHT_FIELDS), new stdClass()),
            ],
            'aggs' => $this->reservationFacetQueryFactory->buildAggregations($input),
        ];

        /**
         * post_filter is applied after the aggregations, so the facet counts survive it. Ranges stay
         * in query.bool.filter, they have no facet and are meant to affect the counts.
         */
        $facetClauses = $this->reservationFacetQueryFactory->buildSelectionClauses($input);
        if ($facetClauses !== []) {
            $body['post_filter'] = [
                'bool' => [
                    'filter' => array_values($facetClauses),
                ],
            ];
        }

        if ($input->cursor !== null) {
            $cursor = SearchCursor::decode($input->cursor);
            if ($input->sort === ReservationSort::RELEVANCE) {
                $body['from'] = $this->resolveOffsetWithinWindow($cursor, $body['size']);
            } else {
                $body['search_after'] = $this->resolveSortValuesFrom($cursor, $input->sort);
            }
        }

        return $body;
    }

    /**
     * Without this the cluster answers 400 and the searcher's catch reports it as 503, which tells
     * the desk to retry something that will never change.
     *
     * @throws InvalidSearchCursorException
     * @throws ResultWindowExceededException
     */
    private function resolveOffsetWithinWindow(SearchCursor $cursor, int $size): int
    {
        if ($cursor->offset === null) {
            throw new InvalidSearchCursorException('This cursor does not belong to the relevance sort.');
        }

        if ($cursor->offset + $size > ReservationIndexDefinition::MAX_RESULT_WINDOW) {
            throw new ResultWindowExceededException('Paging reached the end of the result window.');
        }

        return $cursor->offset;
    }

    /**
     * ReservationSearcher turns a 400 from Elasticsearch into a 503, so a bad cursor would read as an outage.
     *
     * @return non-empty-list<mixed>
     *
     * @throws InvalidSearchCursorException
     */
    private function resolveSortValuesFrom(SearchCursor $cursor, ReservationSort $sort): array
    {
        if ($cursor->sortValues === null) {
            throw new InvalidSearchCursorException('This cursor does not belong to a sorted search.');
        }

        if ($cursor->sort !== $sort) {
            throw new InvalidSearchCursorException('This cursor was issued for a different sort order.');
        }

        $sortClauses = $this->buildSortClauses($sort);
        if (count($cursor->sortValues) !== count($sortClauses)) {
            throw new InvalidSearchCursorException('The cursor carries the wrong number of sort values.');
        }

        foreach ($cursor->sortValues as $position => $sortValue) {
            $sortField = array_key_first($sortClauses[$position]);
            $expectedType = match ($sortField) {
                'arrival', 'updated_at' => 'int',
                'id' => 'string',
                default => throw new LogicException(
                    sprintf('No cursor value type is declared for the sort field %s.', $sortField ?? 'none'),
                ),
            };

            if (get_debug_type($sortValue) !== $expectedType) {
                throw new InvalidSearchCursorException('The cursor carries a sort value Elasticsearch cannot read.');
            }
        }

        return $cursor->sortValues;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTextClauses(?string $query): array
    {
        $query = trim($query ?? '');
        if ($query === '') {
            return [
                'must' => [
                    'match_all' => new stdClass(),
                ],
            ];
        }

        $should = [
            [
                'match' => [
                    self::GUEST_NAME_FIELD => [
                        'query' => $query,
                        'boost' => self::GUEST_NAME_BOOST,
                        'fuzziness' => 'AUTO',
                    ],
                ],
            ],
            [
                'multi_match' => [
                    'query' => $query,
                    'fields' => self::MULTI_MATCH_FIELDS,
                ],
            ],
            [
                'match_bool_prefix' => [
                    self::GUEST_NAME_FIELD => [
                        'query' => $query,
                        'boost' => self::GUEST_NAME_PREFIX_BOOST,
                    ],
                ],
            ],
            [
                'match_bool_prefix' => [
                    'hotel.name' => [
                        'query' => $query,
                        'boost' => self::HOTEL_NAME_PREFIX_BOOST,
                    ],
                ],
            ],
            [
                'prefix' => [
                    'guest.email' => [
                        'value' => $query,
                        'boost' => self::GUEST_EMAIL_PREFIX_BOOST,
                        'case_insensitive' => true,
                    ],
                ],
            ],
            [
                'constant_score' => [
                    'filter' => [
                        'term' => ['number' => $query],
                    ],
                    'boost' => self::NUMBER_BOOST,
                ],
            ],
        ];

        if (preg_match(self::NUMBER_PREFIX_PATTERN, $query) === 1) {
            $should[] = [
                'prefix' => [
                    'number' => [
                        'value' => $query,
                        'boost' => self::NUMBER_PREFIX_BOOST,
                    ],
                ],
            ];
        }

        foreach ($this->buildPhonePrefixes($query) as $phonePrefix) {
            $should[] = [
                'prefix' => [
                    'guest.phone_digits' => [
                        'value' => $phonePrefix,
                        'boost' => self::GUEST_PHONE_PREFIX_BOOST,
                    ],
                ],
            ];
        }

        return [
            'should' => $should,
            'minimum_should_match' => 1,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildPhonePrefixes(string $query): array
    {
        $digits = PhoneDigits::normalize($query);
        if (strlen($digits) < self::MINIMUM_PHONE_DIGITS) {
            return [];
        }

        return [$digits, self::DEFAULT_COUNTRY_CODE . $digits];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildQuery(ReservationSearchInput $input): array
    {
        // an empty filter reaches _explain as a node about _primary_term
        $bool = $this->buildTextClauses($input->text);
        $filterClauses = $this->buildFilterClauses($input);
        if ($filterClauses !== []) {
            $bool['filter'] = $filterClauses;
        }

        return ['bool' => $bool];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFilterClauses(ReservationSearchInput $input): array
    {
        $clauses = [];

        $arrival = array_filter([
            'gte' => $input->arrivalFrom,
            'lte' => $input->arrivalTo,
        ], static fn (?string $bound): bool => $bound !== null);
        if ($arrival !== []) {
            $clauses[] = [
                'range' => ['arrival' => $arrival],
            ];
        }

        $price = array_filter([
            'gte' => $input->priceFrom,
            'lte' => $input->priceTo,
        ], static fn (?int $bound): bool => $bound !== null);
        if ($price !== []) {
            $clauses[] = [
                'range' => ['total_price' => $price],
            ];
        }

        return $clauses;
    }

    /**
     * Every sort ends with the id tiebreaker; search_after and from/size would otherwise skip or duplicate a row
     * at a page boundary.
     *
     * @return list<array<string, string>>
     */
    private function buildSortClauses(ReservationSort $sort): array
    {
        $tiebreaker = ['id' => 'asc'];
        $score = ['_score' => 'desc'];
        $arrival = ['arrival' => 'asc'];
        $updatedAt = ['updated_at' => 'desc'];

        return match ($sort) {
            ReservationSort::RELEVANCE => [$score, $updatedAt, $tiebreaker],
            ReservationSort::ARRIVAL => [$arrival, $tiebreaker],
            ReservationSort::LAST_CHANGE => [$updatedAt, $tiebreaker],
        };
    }
}
