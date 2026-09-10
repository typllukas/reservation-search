<?php

declare(strict_types=1);

namespace Architecture\Rules;

use App\Message\ReservationChanged;
use App\Service\ReservationUpdater;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_starts_with;

/**
 * A second announcer would index a reservation the updater has not written yet.
 *
 * @implements Rule<New_>
 */
final class ReservationChangedInstantiationRule implements Rule
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || $scope->resolveName($node->class) !== ReservationChanged::class) {
            return [];
        }

        $callingClass = $scope->getClassReflection()?->getName() ?? '';
        if ($callingClass === ReservationUpdater::class || str_starts_with($callingClass, 'App\\Tests\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s is announced by %s alone, after the write it announces.',
                ReservationChanged::class,
                ReservationUpdater::class,
            ))->identifier('reservationSearch.announcement')->build(),
        ];
    }
}
