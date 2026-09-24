<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch\DataTransformer;

use App\Elasticsearch\DataTransformer\ArrayToReservationScoreExplanation;
use PHPUnit\Framework\TestCase;

/**
 * @see ArrayToReservationScoreExplanation
 */
final class ArrayToReservationScoreExplanationTest extends TestCase
{
    public function testTheWholeTreeIsReadDownToItsIntegerValues(): void
    {
        $explanation = ArrayToReservationScoreExplanation::transform([
            'matched' => true,
            'explanation' => [
                'value' => 7.5,
                'description' => 'weight(guest.name:test in 2) [PerFieldSimilarity], result of:',
                'details' => [
                    [
                        'value' => 2.5,
                        'description' => 'boost',
                        'details' => [],
                    ],
                    [
                        'value' => 3.0,
                        'description' => 'idf, computed as log(1 + (N - n + 0.5) / (n + 0.5)) from:',
                        'details' => [
                            [
                                'value' => 7,
                                'description' => 'n, number of documents containing term',
                                'details' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(7.5, $explanation->explanation->value);
        self::assertSame(
            'weight(guest.name:test in 2) [PerFieldSimilarity], result of:',
            $explanation->explanation->description,
        );
        self::assertCount(2, $explanation->explanation->details);

        $inverseDocumentFrequency = $explanation->explanation->details[1];
        self::assertSame(
            'idf, computed as log(1 + (N - n + 0.5) / (n + 0.5)) from:',
            $inverseDocumentFrequency->description,
        );
        self::assertSame(7.0, $inverseDocumentFrequency->details[0]->value);
    }
}
