<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Controller\ReservationUpdateController;
use App\Tests\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * PATCH /api/reservations/{id}
 */
final class ReservationUpdateApiTest extends ApiTestCase
{
    /**
     * MapEntity asks MariaDB whether the reservation exists, so the PATCH answers 500 without a database.
     */
    public function testChangingTheStatusOfAReservationThatDoesNotExistIsNotFound(): void
    {
        $client = self::createClient();

        self::assertNotSame([], $this->findRoutedControllersMatching(ReservationUpdateController::class));

        $client->request(
            'PATCH',
            '/api/reservations/' . new Ulid()->toBase32(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['status' => 'confirmed'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }
}
