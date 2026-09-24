<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\GuestSuggestInput;
use App\Elasticsearch\GuestSuggester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class GuestSuggestController
{
    public function __construct(
        private GuestSuggester $guestSuggester,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/guests/suggest',
        name: 'api_guests_suggest',
        methods: ['GET'],
    )]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        GuestSuggestInput $input,
    ): JsonResponse {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize(
                $this->guestSuggester->suggest($input->text, $input->size),
                'json',
            ),
        );
    }
}
