<?php

declare(strict_types=1);

namespace App\Elasticsearch\DataTransformer;

use App\DTO\FacetBucket;
use App\DTO\FacetDimension;
use App\DTO\GuestSummary;
use App\DTO\HotelSummary;
use App\DTO\ReservationHit;
use App\DTO\ReservationSearchResult;
use App\DTO\SearchCursor;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\ReservationFacetQueryFactory;
use App\Elasticsearch\ReservationSearchQueryFactory;
use App\Enum\ReservationSort;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Helper\MixedToString;
use LogicException;

use function array_first;
use function array_key_exists;
use function array_last;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function sprintf;

final class ArrayToReservationSearchResult
{
    /**
     * @param array<array-key, mixed> $response
     */
    public static function transform(
        array $response,
        ReservationSort $sort,
        int $rowsBeforeThisPage,
        int $pageSize,
    ): ReservationSearchResult {
        $hitsEnvelope = ResponseBody::readArray($response, 'hits');

        $hits = array_values(ResponseBody::readArray($hitsEnvelope, 'hits'));

        return new ReservationSearchResult(
            self::readTotal(ResponseBody::readArray($hitsEnvelope, 'total')),
            ResponseBody::readIntegerStrict($response, 'took'),
            array_map(self::buildHit(...), $hits),
            self::buildNextCursor($hits, $sort, $rowsBeforeThisPage, $pageSize),
            self::buildFacets(ResponseBody::readArray($response, 'aggregations')),
        );
    }

    /**
     * @param array<array-key, mixed> $total
     */
    private static function readTotal(array $total): int
    {
        $relation = ResponseBody::readStringStrict($total, 'relation');
        if ($relation !== 'eq') {
            throw new LogicException(sprintf('Total relation %s, the query asks for an exact count.', $relation));
        }

        return ResponseBody::readIntegerStrict($total, 'value');
    }

    private static function buildHit(mixed $hit): ReservationHit
    {
        $hit = ResponseBody::narrowToArray($hit);

        $documentFields = ResponseBody::readArray($hit, '_source');
        $guest = ResponseBody::readArray($documentFields, 'guest');
        $hotel = ResponseBody::readArray($documentFields, 'hotel');

        return new ReservationHit(
            ResponseBody::readStringStrict($documentFields, 'id'),
            ResponseBody::readStringStrict($documentFields, 'number'),
            self::readStatus($documentFields),
            self::readSource($documentFields),
            ResponseBody::readStringStrict($documentFields, 'arrival'),
            ResponseBody::readStringStrict($documentFields, 'departure'),
            ResponseBody::readIntegerStrict($documentFields, 'nights'),
            ResponseBody::readIntegerStrict($documentFields, 'total_price'),
            ResponseBody::readBooleanStrict($documentFields, 'paid'),
            ResponseBody::readNullableString($documentFields, 'note'),
            new GuestSummary(
                ResponseBody::readStringStrict($guest, 'id'),
                ResponseBody::readStringStrict($guest, 'name'),
                ResponseBody::readStringStrict($guest, 'email'),
                ResponseBody::readStringStrict($guest, 'phone'),
            ),
            new HotelSummary(
                ResponseBody::readStringStrict($hotel, 'id'),
                ResponseBody::readStringStrict($hotel, 'code'),
                ResponseBody::readStringStrict($hotel, 'name'),
                ResponseBody::readStringStrict($hotel, 'chain'),
            ),
            self::buildHighlight($hit),
        );
    }

    /**
     * @param array<array-key, mixed> $documentFields
     */
    private static function readStatus(array $documentFields): ReservationStatus
    {
        $value = ResponseBody::readStringStrict($documentFields, 'status');
        $status = ReservationStatus::tryFrom($value);
        if ($status === null) {
            throw new LogicException(sprintf('The index holds the unknown reservation status %s.', $value));
        }

        return $status;
    }

    /**
     * @param array<array-key, mixed> $documentFields
     */
    private static function readSource(array $documentFields): ReservationSource
    {
        $value = ResponseBody::readStringStrict($documentFields, 'source');
        $source = ReservationSource::tryFrom($value);
        if ($source === null) {
            throw new LogicException(sprintf('The index holds the unknown reservation source %s.', $value));
        }

        return $source;
    }

    /**
     * @param array<array-key, mixed> $hit
     *
     * @return array<string, array<int, string>>
     */
    private static function buildHighlight(array $hit): array
    {
        $fragmentsByIndexField = ResponseBody::readArray($hit, 'highlight');

        $highlight = [];
        foreach (ReservationSearchQueryFactory::HIGHLIGHT_FIELDS as $indexFieldName => $responseFieldName) {
            $fragmentTexts = array_map(
                MixedToString::transformStrict(...),
                array_values(ResponseBody::readArray($fragmentsByIndexField, $indexFieldName)),
            );
            if ($fragmentTexts === []) {
                continue;
            }

            $highlight[$responseFieldName] = array_values(
                array_unique([...$highlight[$responseFieldName] ?? [], ...$fragmentTexts]),
            );
        }

        return $highlight;
    }

    /**
     * A short page is the last one, and a cursor onto it would cost the client a round trip to learn that.
     *
     * @param array<int, mixed> $hits
     */
    private static function buildNextCursor(
        array $hits,
        ReservationSort $sort,
        int $rowsBeforeThisPage,
        int $pageSize,
    ): ?string {
        if (count($hits) < $pageSize) {
            return null;
        }

        if ($sort === ReservationSort::RELEVANCE) {
            return SearchCursor::createForOffset($rowsBeforeThisPage + count($hits))->encode();
        }

        $sortValues = ResponseBody::readArray(ResponseBody::narrowToArray(array_last($hits)), 'sort');
        if ($sortValues === []) {
            throw new LogicException('A sorted search returned a hit without sort values.');
        }

        return SearchCursor::createForSortValues($sort, array_values($sortValues))->encode();
    }

    /**
     * Every facet is wrapped in a 'filter' aggregation, so the buckets sit one level deeper under
     * the dimension name.
     *
     * @param array<array-key, mixed> $aggregations
     *
     * @return array<string, FacetDimension>
     */
    private static function buildFacets(array $aggregations): array
    {
        $facets = [];
        foreach ($aggregations as $dimension => $aggregation) {
            $dimensionName = MixedToString::transformStrict($dimension);
            $countingAggregation = ResponseBody::readArray(
                ResponseBody::narrowToArray($aggregation),
                $dimensionName,
            );

            $countsParents = array_key_exists($dimensionName, ReservationFacetQueryFactory::NESTED_FACET_FIELDS);
            if ($countsParents) {
                $countingAggregation = ResponseBody::readArray($countingAggregation, $dimensionName);
            }

            $buckets = array_map(
                static fn (mixed $bucket): FacetBucket => self::buildFacetBucket($bucket, $countsParents),
                array_values(ResponseBody::readArray($countingAggregation, 'buckets')),
            );

            $facets[$dimensionName] = new FacetDimension(
                $buckets,
                ResponseBody::readIntegerStrict($countingAggregation, 'sum_other_doc_count'),
            );
        }

        return $facets;
    }

    private static function buildFacetBucket(mixed $bucket, bool $countsParents): FacetBucket
    {
        $bucket = ResponseBody::narrowToArray($bucket);

        return new FacetBucket(
            ResponseBody::readStringStrict($bucket, 'key'),
            $countsParents
                ? ResponseBody::readIntegerStrict(
                    ResponseBody::readArray($bucket, ReservationFacetQueryFactory::PARENT_COUNT_AGGREGATION),
                    'doc_count',
                )
                : ResponseBody::readIntegerStrict($bucket, 'doc_count'),
            self::buildSubAggregationKey($bucket, 'label'),
            self::buildSubAggregationKey($bucket, 'code'),
        );
    }

    /**
     * @param array<array-key, mixed> $bucket
     */
    private static function buildSubAggregationKey(array $bucket, string $subAggregation): ?string
    {
        $buckets = ResponseBody::readArray(
            ResponseBody::readArray($bucket, $subAggregation),
            'buckets',
        );

        if ($buckets === []) {
            return null;
        }

        return ResponseBody::readStringStrict(ResponseBody::narrowToArray(array_first($buckets)), 'key');
    }
}
