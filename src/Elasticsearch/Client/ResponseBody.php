<?php

declare(strict_types=1);

namespace App\Elasticsearch\Client;

use App\Helper\MixedToFloat;
use App\Helper\MixedToInteger;
use App\Helper\MixedToString;
use Elastic\Elasticsearch\Response\Elasticsearch;
use LogicException;

use function get_debug_type;
use function is_array;
use function is_bool;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_UNICODE;

/**
 * A document from the cluster is a shape nobody checked.
 */
final class ResponseBody
{
    /**
     * The client's return type is Elasticsearch|Promise; the async mode is unused here.
     *
     * @return array<array-key, mixed>
     */
    public static function read(mixed $response): array
    {
        if (!$response instanceof Elasticsearch) {
            throw new LogicException(
                'The Elasticsearch client returned an asynchronous response this code does not expect.',
            );
        }

        return $response->asArray();
    }

    /**
     * Elasticsearch leaves a key out rather than sending null: e.g. a hit that matched outside
     * ReservationSearchQueryFactory::HIGHLIGHT_FIELDS carries no 'highlight' key.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    public static function readArray(array $body, string $key): array
    {
        return self::narrowToArray($body[$key] ?? []);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function narrowToArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException(sprintf('Expected an array in the response, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readStringStrict(array $body, string $key): string
    {
        try {
            return MixedToString::transformStrict($body[$key] ?? null);
        } catch (LogicException $exception) {
            throw self::describeKey($key, $exception);
        }
    }

    /**
     * A document field the writer may leave null, unlike a key Elasticsearch simply omits.
     *
     * @param array<array-key, mixed> $body
     */
    public static function readNullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return $value === null ? null : MixedToString::transformStrict($value);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readIntegerStrict(array $body, string $key): int
    {
        try {
            return MixedToInteger::transformStrict($body[$key] ?? null);
        } catch (LogicException $exception) {
            throw self::describeKey($key, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readFloatStrict(array $body, string $key): float
    {
        try {
            return MixedToFloat::transformStrict($body[$key] ?? null);
        } catch (LogicException $exception) {
            throw self::describeKey($key, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readBooleanStrict(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        if (!is_bool($value)) {
            throw new LogicException(sprintf(
                'Expected a boolean under %s in the response, got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private static function describeKey(string $key, LogicException $exception): LogicException
    {
        return new LogicException(
            sprintf('The response key %s did not read: %s', $key, $exception->getMessage()),
            previous: $exception,
        );
    }

    /**
     * _bulk hides item errors under items[].<operation>.error and still answers 200. The one key per
     * item is the operation name, and both index and delete are used here.
     *
     * @param array<array-key, mixed> $response
     *
     * @return array<int, string> error descriptions
     */
    public static function findBulkFailures(array $response): array
    {
        if (($response['errors'] ?? false) !== true) {
            return [];
        }

        $failures = [];
        foreach (self::readArray($response, 'items') as $item) {
            foreach (self::narrowToArray($item) as $operationResult) {
                $error = self::narrowToArray($operationResult)['error'] ?? null;
                if ($error === null) {
                    continue;
                }

                $encodedError = json_encode($error, JSON_UNESCAPED_UNICODE);
                $failures[] = $encodedError === false ? 'the error could not be read' : $encodedError;
            }
        }

        return $failures;
    }
}
