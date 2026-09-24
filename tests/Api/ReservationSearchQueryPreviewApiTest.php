<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Controller\ReservationSearchQueryPreviewController;
use App\Tests\ApiTestCase;

final class ReservationSearchQueryPreviewApiTest extends ApiTestCase
{
    public function testTheQueryPreviewDoesNotExistOutsideDev(): void
    {
        self::bootKernel();

        self::assertSame([], $this->findRoutedControllersMatching(ReservationSearchQueryPreviewController::class));
    }
}
