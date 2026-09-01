<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ReservationSearchInput;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Enum\RoomKind;
use LogicException;
use stdClass;
use Symfony\Component\Uid\Ulid;

use function array_diff_key;
use function array_map;
use function array_values;
use function count;
use function sprintf;

final readonly class ReservationFacetQueryFactory
{
    private const array FLAT_FACET_FIELDS = [
        'status' => 'status',
        'source' => 'source',
        'hotel' => 'hotel.id',
    ];

    /**
     * A nested field needs the nested wrapper and reverse_nested.
     */
    public const array NESTED_FACET_FIELDS = [
        'roomKind' => [
            'path' => 'rooms',
            'field' => 'rooms.kind',
        ],
    ];

    /**
     * Names the reverse_nested sub-aggregation that counts reservations instead of rooms; the response reader
     * looks the count up under the same name.
     */
    public const string PARENT_COUNT_AGGREGATION = 'parent_count';

    /** The hotel key is a ULID; the label comes with the bucket so the client needs no second request. */
    private const array FACET_NAMING_FIELDS = [
        'hotel' => [
            'label' => 'hotel.name.keyword',
            'code' => 'hotel.code',
        ],
    ];

    private const int HOTEL_FACET_SIZE = 20;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildSelectionClauses(ReservationSearchInput $input): array
    {
        $clauses = [];

        if ($input->status !== null && $input->status !== []) {
            $clauses['status'] = [
                'terms' => [
                    self::FLAT_FACET_FIELDS['status'] => array_map(
                        static fn (ReservationStatus $status): string => $status->value,
                        $input->status,
                    ),
                ],
            ];
        }

        if ($input->source !== null && $input->source !== []) {
            $clauses['source'] = [
                'terms' => [
                    self::FLAT_FACET_FIELDS['source'] => array_map(
                        static fn (ReservationSource $source): string => $source->value,
                        $input->source,
                    ),
                ],
            ];
        }

        if ($input->hotel !== null && $input->hotel !== []) {
            $clauses['hotel'] = [
                'terms' => [
                    self::FLAT_FACET_FIELDS['hotel'] => array_map(
                        static fn (Ulid $id): string => $id->toBase32(),
                        $input->hotel,
                    ),
                ],
            ];
        }

        if ($input->roomKind !== null && $input->roomKind !== []) {
            $clauses['roomKind'] = [
                'nested' => [
                    'path' => self::NESTED_FACET_FIELDS['roomKind']['path'],
                    'query' => [
                        'terms' => [
                            self::NESTED_FACET_FIELDS['roomKind']['field'] => array_map(
                                static fn (RoomKind $kind): string => $kind->value,
                                $input->roomKind,
                            ),
                        ],
                    ],
                ],
            ];
        }

        return $clauses;
    }

    /**
     * Each facet counts with the other dimensions' filters and without its own; post_filter alone would
     * drop them all. The filter wrapper stays even when empty so the response keeps one shape.
     *
     * @return array<string, mixed>
     */
    public function buildAggregations(ReservationSearchInput $input): array
    {
        $selectionClauses = $this->buildSelectionClauses($input);
        $aggregations = [];

        foreach (self::FLAT_FACET_FIELDS as $dimension => $field) {
            $aggregations[$dimension] = $this->buildFacetAggregation(
                $dimension,
                $selectionClauses,
                $this->buildTermsAggregation($dimension, $field),
            );
        }

        foreach (self::NESTED_FACET_FIELDS as $dimension => $nestedFacet) {
            $aggregations[$dimension] = $this->buildFacetAggregation(
                $dimension,
                $selectionClauses,
                $this->buildNestedTermsAggregation($dimension, $nestedFacet),
            );
        }

        return $aggregations;
    }

    /**
     * @param array<string, array<string, mixed>> $selectionClauses
     * @param array<string, mixed> $bucketAggregation
     *
     * @return array<string, mixed>
     */
    private function buildFacetAggregation(string $dimension, array $selectionClauses, array $bucketAggregation): array
    {
        $otherDimensionClauses = array_values(array_diff_key($selectionClauses, [$dimension => null]));

        return [
            'filter' => $otherDimensionClauses === []
                ? ['match_all' => new stdClass()] : ['bool' => ['filter' => $otherDimensionClauses]],
            'aggs' => [$dimension => $bucketAggregation],
        ];
    }

    /**
     * The inner level repeats the dimension name so the response reads like a flat facet one level deeper.
     *
     * @param array{path: string, field: string} $nestedFacet
     *
     * @return array<string, mixed>
     */
    private function buildNestedTermsAggregation(string $dimension, array $nestedFacet): array
    {
        return [
            'nested' => [
                'path' => $nestedFacet['path'],
            ],
            'aggs' => [
                $dimension => [
                    'terms' => [
                        'field' => $nestedFacet['field'],
                        'size' => $this->resolveFacetSize($dimension),
                        /**
                         * doc_count would order by rooms while the shown number counts parents. Only the bare
                         * sub-aggregation name is accepted, parent_count>_count and parent_count._count fail.
                         */
                        'order' => [self::PARENT_COUNT_AGGREGATION => 'desc'],
                    ],
                    'aggs' => [
                        self::PARENT_COUNT_AGGREGATION => [
                            'reverse_nested' => new stdClass(),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function resolveFacetSize(string $dimension): int
    {
        return match ($dimension) {
            'status' => count(ReservationStatus::cases()),
            'source' => count(ReservationSource::cases()),
            'roomKind' => count(RoomKind::cases()),
            'hotel' => self::HOTEL_FACET_SIZE,
            default => throw new LogicException(
                sprintf('No facet size is declared for the dimension %s.', $dimension),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTermsAggregation(string $dimension, string $field): array
    {
        $aggregation = [
            'terms' => [
                'field' => $field,
                'size' => $this->resolveFacetSize($dimension),
            ],
        ];

        foreach (self::FACET_NAMING_FIELDS[$dimension] ?? [] as $namingAggregation => $namingField) {
            // keyed by hotel.id, so every document in a bucket shares one name and one code; size 1 reads them
            $aggregation['aggs'][$namingAggregation] = [
                'terms' => [
                    'field' => $namingField,
                    'size' => 1,
                ],
            ];
        }

        return $aggregation;
    }
}
