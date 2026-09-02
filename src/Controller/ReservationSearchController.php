<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ReservationSearchInput;
use App\Elasticsearch\ReservationSearcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class ReservationSearchController
{
    public function __construct(
        private ReservationSearcher $reservationSearcher,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/reservations',
        name: 'api_reservations_search',
        methods: ['GET'],
    )]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ReservationSearchInput $input,
    ): JsonResponse {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize($this->reservationSearcher->search($input), 'json'),
        );
    }
}
