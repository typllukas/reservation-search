<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/guests/suggest
 */
final class GuestSuggestApiTest extends ApiTestCase
{
    public function testAPrefixShorterThanTheMinimumIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/guests/suggest?text=a');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame(['text'], $this->getViolatedFields($client));
    }
}
