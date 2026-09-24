<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ReservationSearchInput;
use App\Elasticsearch\ReservationScoreExplainer;
use App\Entity\Reservation;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class ReservationExplainController
{
    public function __construct(
        private ReservationScoreExplainer $reservationScoreExplainer,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/reservations/{id}/explain',
        name: 'api_reservations_explain',
        requirements: ['id' => Requirement::ULID],
        methods: ['GET'],
        env: 'dev',
    )]
    public function __invoke(
        #[MapEntity]
        Reservation $reservation,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ReservationSearchInput $input,
    ): JsonResponse {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize(
                $this->reservationScoreExplainer->explain($reservation->getId(), $input),
                'json',
            ),
        );
    }
}
