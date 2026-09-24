<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Passed on as _explain returns it; flattening would mean parsing description text that changes between versions.
 */
final readonly class ScoreExplanation
{
    /**
     * @param list<ScoreExplanation> $details
     */
    public function __construct(
        public float $value,
        public string $description,
        public array $details,
    ) {
    }
}
