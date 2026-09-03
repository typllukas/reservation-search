<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ReservationUpdateInput;
use App\Entity\Reservation;
use App\Service\ReservationUpdater;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final readonly class ReservationUpdateController
{
    public function __construct(
        private ReservationUpdater $reservationUpdater,
    ) {
    }

    #[Route(
        '/api/reservations/{id}',
        name: 'api_reservations_update',
        requirements: ['id' => Requirement::ULID],
        methods: ['PATCH'],
    )]
    public function __invoke(
        #[MapEntity]
        Reservation $reservation,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ReservationUpdateInput $input,
    ): Response {
        $this->reservationUpdater->update($reservation, $input, true);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
