<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\ReservationUpdateFlushArgumentRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see ReservationUpdateFlushArgumentRule
 *
 * @extends RuleTestCase<ReservationUpdateFlushArgumentRule>
 */
final class ReservationUpdateFlushArgumentRuleTest extends RuleTestCase
{
    private const string EXPECTED_MESSAGE = 'A caller of ReservationUpdater::update() flushes, '
        . 'otherwise the index gets the old updated_at.';

    protected function getRule(): Rule
    {
        return new ReservationUpdateFlushArgumentRule();
    }

    public function testOnlyCallersThatLeaveTheFlushToSomebodyElseAreReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/ReservationUpdateCalls.php'], [
            [self::EXPECTED_MESSAGE, 20],
            [self::EXPECTED_MESSAGE, 25],
            [self::EXPECTED_MESSAGE, 40],
        ]);
    }
}
