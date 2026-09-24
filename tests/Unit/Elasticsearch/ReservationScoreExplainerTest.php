<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\DTO\ReservationSearchInput;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\Exception\ReservationNotIndexedException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\ReservationFacetQueryFactory;
use App\Elasticsearch\ReservationScoreExplainer;
use App\Elasticsearch\ReservationSearchQueryFactory;
use App\Enum\ReservationStatus;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Ulid;

use function json_decode;
use function strval;

use const JSON_THROW_ON_ERROR;

/**
 * @see ReservationScoreExplainer
 */
final class ReservationScoreExplainerTest extends TestCase
{
    private function buildExplainerAnswering(int $status, string $body): ReservationScoreExplainer
    {
        $response = new Response(
            $status,
            [
                'Content-Type' => 'application/json',
                Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            ],
            $body,
        );
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        return new ReservationScoreExplainer(
            ClientBuilder::create()->setHttpClient($httpClient)->build(),
            new ReservationSearchQueryFactory(new ReservationFacetQueryFactory()),
            new IndexNameFactory(''),
        );
    }

    /**
     * @param list<RequestInterface> $sentRequests
     */
    private function buildExplainerRecording(array &$sentRequests): ReservationScoreExplainer
    {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$sentRequests): ResponseInterface {
                $sentRequests[] = $request;

                return new Response(
                    200,
                    [
                        'Content-Type' => 'application/json',
                        Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
                    ],
                    '{"matched":true,"explanation":{"value":3.5,"description":"weight(guest.name:test)"}}',
                );
            },
        );

        return new ReservationScoreExplainer(
            ClientBuilder::create()->setHttpClient($httpClient)->build(),
            new ReservationSearchQueryFactory(new ReservationFacetQueryFactory()),
            new IndexNameFactory(''),
        );
    }

    public function testTheExplanationIsBuiltFromTheResponse(): void
    {
        $explanation = $this->buildExplainerAnswering(
            200,
            '{"matched":true,"explanation":{"value":3.5,"description":"weight(guest.name:test)"}}',
        )->explain(new Ulid(), new ReservationSearchInput(text: 'test'));

        self::assertTrue($explanation->matched);
        self::assertSame(3.5, $explanation->explanation->value);
        self::assertSame([], $explanation->explanation->details);
    }

    /**
     * The scoring query, not the search body: build() would carry the status filter, the aggregations
     * and the sort along with it.
     */
    public function testTheScoringQueryAloneIsSentToTheDocumentBeingExplained(): void
    {
        $sentRequests = [];
        $reservationId = new Ulid();
        $input = new ReservationSearchInput(text: 'test', status: [ReservationStatus::CONFIRMED]);

        $this->buildExplainerRecording($sentRequests)->explain($reservationId, $input);

        self::assertCount(1, $sentRequests);
        self::assertSame('/reservations/_explain/' . $reservationId->toBase32(), $sentRequests[0]->getUri()->getPath());
        self::assertSame(
            ['query' => new ReservationSearchQueryFactory(new ReservationFacetQueryFactory())->buildQuery($input)],
            ResponseBody::narrowToArray(
                json_decode(strval($sentRequests[0]->getBody()), true, flags: JSON_THROW_ON_ERROR),
            ),
        );
    }

    public function testADocumentMissingFromTheIndexIsNotAnOutage(): void
    {
        $this->expectException(ReservationNotIndexedException::class);

        $this->buildExplainerAnswering(404, '{"_index":"reservations","found":false}')
            ->explain(new Ulid(), new ReservationSearchInput(text: 'test'));
    }

    public function testAMissingIndexIsAnOutageAndNotAMissingDocument(): void
    {
        $this->expectException(SearchUnavailableException::class);

        $this->buildExplainerAnswering(404, '{"error":{"type":"index_not_found_exception"}}')
            ->explain(new Ulid(), new ReservationSearchInput(text: 'test'));
    }

    public function testAnyOtherRefusalIsAnOutage(): void
    {
        $this->expectException(SearchUnavailableException::class);

        $this->buildExplainerAnswering(503, '{"error":"unavailable"}')
            ->explain(new Ulid(), new ReservationSearchInput(text: 'test'));
    }
}
