<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ReservationSearchInput;
use App\Elasticsearch\ReservationSearchQueryFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ReservationSearchQueryPreviewController
{
    public function __construct(
        private ReservationSearchQueryFactory $reservationSearchQueryFactory,
    ) {
    }

    #[Route(
        '/api/reservations/query-preview',
        name: 'api_reservations_query_preview',
        methods: ['GET'],
        env: 'dev',
    )]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ReservationSearchInput $input,
    ): JsonResponse {
        return new JsonResponse($this->reservationSearchQueryFactory->build($input));
    }
}
