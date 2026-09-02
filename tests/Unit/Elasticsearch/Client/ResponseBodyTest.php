<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch\Client;

use App\Elasticsearch\Client\ResponseBody;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @see ResponseBody
 */
final class ResponseBodyTest extends TestCase
{
    /**
     * Elasticsearch answers 'not_found' without an 'error' key, so the batch must report only the
     * item that carries one.
     */
    public function testDeletingSomethingThatIsNotThereIsNotAFailure(): void
    {
        $failures = ResponseBody::findBulkFailures([
            'errors' => true,
            'items' => [
                [
                    'delete' => [
                        '_id' => 'A',
                        'result' => 'not_found',
                    ],
                ],
                [
                    'delete' => [
                        '_id' => 'B',
                        'error' => ['type' => 'index_not_found_exception'],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('index_not_found_exception', $failures[0]);
    }

    public function testFailedWritesAreReported(): void
    {
        $failures = ResponseBody::findBulkFailures([
            'errors' => true,
            'items' => [
                [
                    'index' => [
                        '_id' => 'A',
                        'error' => ['type' => 'strict_dynamic_mapping_exception'],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('strict_dynamic_mapping_exception', $failures[0]);
    }

    public function testAMissingKeyReadsAsAnEmptyArray(): void
    {
        self::assertSame([], ResponseBody::readArray(['hits' => []], 'aggregations'));
    }

    /**
     * The rest_total_hits_as_int parameter turns hits.total into a number, and an empty facet would
     * hide it.
     */
    public function testAKeyHoldingSomethingOtherThanAnArrayIsAFailure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Expected an array in the response, got int.');

        ResponseBody::readArray(['total' => 7], 'total');
    }
}
