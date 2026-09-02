<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The facet selections are not in the explanation, they sit in post_filter which the explain query leaves out.
 */
final readonly class ReservationScoreExplanation
{
    public function __construct(
        public bool $matched,
        public ScoreExplanation $explanation,
    ) {
    }
}
