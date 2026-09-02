<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\DTO\ReservationSearchInput;
use App\DTO\SearchCursor;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\ReservationFacetQueryFactory;
use App\Elasticsearch\ReservationSearcher;
use App\Elasticsearch\ReservationSearchQueryFactory;
use App\Enum\ReservationSort;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Http\Client\Exception\NetworkException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * A stubbed transport covers the two things the class decides, the next cursor and the 503.
 *
 * @see ReservationSearcher
 */
final class ReservationSearcherTest extends TestCase
{
    private function buildSearcher(ClientInterface $httpClient): ReservationSearcher
    {
        return new ReservationSearcher(
            ClientBuilder::create()->setHttpClient($httpClient)->build(),
            new ReservationSearchQueryFactory(new ReservationFacetQueryFactory()),
            new IndexNameFactory(''),
        );
    }

    /**
     * @param list<string> $hitIds
     *
     * @return array<string, mixed>
     */
    private function buildSearchResponseBody(array $hitIds): array
    {
        $hits = [];
        foreach ($hitIds as $hitId) {
            $hits[] = [
                '_source' => [
                    'id' => $hitId,
                    'number' => '0000-000000',
                    'status' => 'confirmed',
                    'source' => 'travel_agency',
                    'arrival' => '2026-09-14',
                    'departure' => '2026-09-17',
                    'nights' => 3,
                    'total_price' => 250000,
                    'paid' => true,
                    'note' => null,
                    'guest' => [
                        'id' => '01M1CEXXTGKAFBCS4Z5D5VWAGP',
                        'name' => 'Test Guest',
                        'email' => 'test@test.com',
                        'phone' => '+00000000',
                    ],
                    'hotel' => [
                        'id' => '01M1CEXXJK9H2MVRXH0TE13WYP',
                        'code' => 'H-0000',
                        'name' => 'Test Hotel',
                        'chain' => 'Test Chain',
                    ],
                ],
            ];
        }

        return [
            'took' => 3,
            'hits' => [
                'total' => [
                    'value' => 7,
                    'relation' => 'eq',
                ],
                'hits' => $hits,
            ],
        ];
    }

    /**
     * Two hits, so offset + 1 and offset + hits on the page give different answers.
     */
    public function testTheNextCursorContinuesTheOffsetThatProducedThePage(): void
    {
        $hitIds = ['01M1CEXXTK566P0E1WFXK6FSPD', '01M1CEXXTK566P0E1WFXK6FSPE'];
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(new Response(
            200,
            [
                'Content-Type' => 'application/json',
                Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            ],
            json_encode($this->buildSearchResponseBody($hitIds), JSON_THROW_ON_ERROR),
        ));

        $pageOffset = 20;
        $result = $this->buildSearcher($httpClient)->search(new ReservationSearchInput(
            sort: ReservationSort::RELEVANCE,
            size: 2,
            cursor: SearchCursor::createForOffset($pageOffset)->encode(),
        ));

        self::assertSame(7, $result->total);
        self::assertNotNull($result->nextCursor);
        self::assertSame($pageOffset + count($hitIds), SearchCursor::decode($result->nextCursor)->offset);
    }

    public function testAnUnreachableClusterIsReportedAsSearchUnavailable(): void
    {
        $this->expectException(SearchUnavailableException::class);

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            static fn (RequestInterface $request): never => throw new NetworkException(
                'The host is unreachable.',
                $request,
            ),
        );

        $this->buildSearcher($httpClient)->search(new ReservationSearchInput());
    }
}
