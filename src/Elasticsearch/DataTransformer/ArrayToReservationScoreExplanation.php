<?php

declare(strict_types=1);

namespace App\Elasticsearch\DataTransformer;

use App\DTO\ReservationScoreExplanation;
use App\DTO\ScoreExplanation;
use App\Elasticsearch\Client\ResponseBody;

final class ArrayToReservationScoreExplanation
{
    /**
     * @param array<array-key, mixed> $response the _explain response body
     */
    public static function transform(array $response): ReservationScoreExplanation
    {
        return new ReservationScoreExplanation(
            ResponseBody::readBooleanStrict($response, 'matched'),
            self::buildExplanation(ResponseBody::readArray($response, 'explanation')),
        );
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function buildExplanation(array $node): ScoreExplanation
    {
        $details = [];
        foreach (ResponseBody::readArray($node, 'details') as $detail) {
            $details[] = self::buildExplanation(ResponseBody::narrowToArray($detail));
        }

        return new ScoreExplanation(
            ResponseBody::readFloatStrict($node, 'value'),
            ResponseBody::readStringStrict($node, 'description'),
            $details,
        );
    }
}
