<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\ReservationWriteCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see ReservationWriteCallRule
 *
 * @extends RuleTestCase<ReservationWriteCallRule>
 */
final class ReservationWriteCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ReservationWriteCallRule();
    }

    public function testOnlyWritesToAReservationOutsideTheUpdaterAreReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/ReservationWriteCalls.php'], [
            [
                'setPaid() changes a reservation; that belongs in App\Service\ReservationUpdater, '
                    . 'which announces the change to the index.',
                14,
            ],
            [
                'setNote() changes a reservation; that belongs in App\Service\ReservationUpdater, '
                    . 'which announces the change to the index.',
                19,
            ],
            [
                'addRoom() changes a reservation; that belongs in App\Service\ReservationUpdater, '
                    . 'which announces the change to the index.',
                24,
            ],
            [
                'setPrice() changes a reservation; that belongs in App\Service\ReservationUpdater, '
                    . 'which announces the change to the index.',
                43,
            ],
        ]);
    }

    public function testTheUpdaterItselfMayChangeAReservation(): void
    {
        $this->analyse([__DIR__ . '/../../../src/Service/ReservationUpdater.php'], []);
    }
}
