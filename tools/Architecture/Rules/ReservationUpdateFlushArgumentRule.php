<?php

declare(strict_types=1);

namespace Architecture\Rules;

use App\Service\ReservationUpdater;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function str_starts_with;

/**
 * The document would carry the timestamp from before the write, and the catch-up sorts on it.
 *
 * @implements Rule<MethodCall>
 */
final class ReservationUpdateFlushArgumentRule implements Rule
{
    private const int FLUSH_ARGUMENT_INDEX = 2;
    private const string FLUSH_ARGUMENT_NAME = 'flush';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'update') {
            return [];
        }

        if (!new ObjectType(ReservationUpdater::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        if (str_starts_with($scope->getClassReflection()?->getName() ?? '', 'App\\Tests\\')) {
            return [];
        }

        foreach ($node->getArgs() as $argumentIndex => $argument) {
            $isFlushArgument = $argument->name instanceof Identifier
                ? $argument->name->toString() === self::FLUSH_ARGUMENT_NAME
                : $argumentIndex === self::FLUSH_ARGUMENT_INDEX;

            if ($isFlushArgument && $scope->getType($argument->value)->isTrue()->yes()) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                'A caller of ReservationUpdater::update() flushes, otherwise the index gets the old updated_at.',
            )->identifier('reservationSearch.flushBeforeIndexing')->build(),
        ];
    }
}
