<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Controller\ReservationExplainController;
use App\Tests\ApiTestCase;

/**
 * GET /api/reservations/{id}/explain
 */
final class ReservationExplainApiTest extends ApiTestCase
{
    public function testTheExplainDoesNotExistOutsideDev(): void
    {
        self::bootKernel();

        self::assertSame([], $this->findRoutedControllersMatching(ReservationExplainController::class));
    }
}
