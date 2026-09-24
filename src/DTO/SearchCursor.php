<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ReservationSort;
use App\Exception\InvalidSearchCursorException;
use JsonException;

use function array_is_list;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * _score shifts for every document whenever the index is written to, so a relevance cursor carries a row
 * count instead of a sort value.
 */
final readonly class SearchCursor
{
    /**
     * @param non-empty-list<mixed>|null $sortValues
     */
    private function __construct(
        public ?int $offset,
        public ?ReservationSort $sort,
        public ?array $sortValues,
    ) {
    }

    public static function createForOffset(int $offset): self
    {
        return new self($offset, null, null);
    }

    /**
     * arrival and last_change both carry two values, so nothing in the shape tells one order's cursor
     * from the other's.
     *
     * @param non-empty-list<mixed> $sortValues the values from the last hit's 'sort' array
     */
    public static function createForSortValues(ReservationSort $sort, array $sortValues): self
    {
        return new self(null, $sort, $sortValues);
    }

    /**
     * @throws InvalidSearchCursorException
     */
    public static function decode(string $cursor): self
    {
        $json = base64_decode($cursor, true);
        if ($json === false) {
            throw new InvalidSearchCursorException('The cursor is not valid base64.');
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidSearchCursorException('The cursor does not contain valid JSON.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new InvalidSearchCursorException('The cursor must decode to an object.');
        }

        $offset = $payload['offset'] ?? null;
        $sortName = $payload['sort'] ?? null;
        $sortValues = $payload['after'] ?? null;

        if (is_int($offset) && $offset >= 0 && $sortName === null && $sortValues === null) {
            return self::createForOffset($offset);
        }

        $sort = is_string($sortName) ? ReservationSort::tryFrom($sortName) : null;
        if (
            $sort !== null
            && $offset === null
            && is_array($sortValues)
            && array_is_list($sortValues)
            && $sortValues !== []
        ) {
            return self::createForSortValues($sort, $sortValues);
        }

        throw new InvalidSearchCursorException(
            'The cursor must carry either an offset or a known sort order with a non-empty list of values.',
        );
    }

    public function encode(): string
    {
        $payload = $this->sort instanceof ReservationSort
            ? ['sort' => $this->sort->value, 'after' => $this->sortValues] : ['offset' => $this->offset];

        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
