<?php

declare(strict_types=1);

namespace Architecture\Rules;

use App\Entity\Reservation;
use App\Entity\ReservationRoom;
use App\Service\ReservationUpdater;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_any;
use function in_array;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * A write outside the updater sends no ReservationChanged, so the index keeps the old document.
 * Rooms count as the reservation; the guest and the hotel are shared, and a reindex is what fixes those.
 *
 * @implements Rule<MethodCall>
 */
final class ReservationWriteCallRule implements Rule
{
    private const string READ_METHOD_PATTERN = '/^(get|is|has)[A-Z]/';

    private const array OWNED_ENTITIES = [Reservation::class, ReservationRoom::class];

    /**
     * Reservation is here for addRoom(), which sets the room's back reference; the call into it is guarded.
     */
    private const array WRITE_PATH_OWNERS = [ReservationUpdater::class, Reservation::class];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $calledMethod = $node->name->toString();
        if (preg_match(self::READ_METHOD_PATTERN, $calledMethod) === 1) {
            return [];
        }

        if (!$this->isOwnedByAReservation($scope->getType($node->var))) {
            return [];
        }

        $callingClass = $scope->getClassReflection()?->getName() ?? '';
        if (in_array($callingClass, self::WRITE_PATH_OWNERS, true) || str_starts_with($callingClass, 'App\\Tests\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() changes a reservation; that belongs in %s, which announces the change to the index.',
                $calledMethod,
                ReservationUpdater::class,
            ))->identifier('reservationSearch.writePath')->build(),
        ];
    }

    private function isOwnedByAReservation(Type $receiverType): bool
    {
        return array_any(
            self::OWNED_ENTITIES,
            static fn (string $className): bool => new ObjectType($className)->isSuperTypeOf($receiverType)->yes(),
        );
    }
}
