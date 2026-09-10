<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\ReservationChangedInstantiationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see ReservationChangedInstantiationRule
 *
 * @extends RuleTestCase<ReservationChangedInstantiationRule>
 */
final class ReservationChangedInstantiationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ReservationChangedInstantiationRule();
    }

    public function testOnlyTheReservationChangedMessageOutsideTheUpdaterIsReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/ReservationChangedAnnouncements.php'], [
            [
                'App\Message\ReservationChanged is announced by App\Service\ReservationUpdater alone, '
                    . 'after the write it announces.',
                15,
            ],
        ]);
    }

    public function testTheUpdaterItselfMayAnnounceAChange(): void
    {
        $this->analyse([__DIR__ . '/../../../src/Service/ReservationUpdater.php'], []);
    }
}
