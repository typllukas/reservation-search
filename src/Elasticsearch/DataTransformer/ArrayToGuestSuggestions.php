<?php

declare(strict_types=1);

namespace App\Elasticsearch\DataTransformer;

use App\DTO\GuestSuggestion;
use App\Elasticsearch\Client\ResponseBody;

use function array_map;
use function array_values;

final class ArrayToGuestSuggestions
{
    /**
     * @param array<array-key, mixed> $response the _search response body
     *
     * @return array<int, GuestSuggestion>
     */
    public static function transform(array $response): array
    {
        $hits = ResponseBody::readArray($response, 'hits');

        return array_map(self::buildSuggestion(...), array_values(ResponseBody::readArray($hits, 'hits')));
    }

    /**
     * The id comes from '_id'; the guest document carries no id field.
     */
    private static function buildSuggestion(mixed $hit): GuestSuggestion
    {
        $hit = ResponseBody::narrowToArray($hit);

        $documentFields = ResponseBody::readArray($hit, '_source');

        return new GuestSuggestion(
            ResponseBody::readStringStrict($hit, '_id'),
            ResponseBody::readStringStrict($documentFields, 'name'),
            ResponseBody::readStringStrict($documentFields, 'email'),
        );
    }
}
