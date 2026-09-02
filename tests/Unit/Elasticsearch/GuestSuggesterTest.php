<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\Elasticsearch\GuestSuggester;
use App\Elasticsearch\IndexNameFactory;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function json_encode;
use function strval;

use const JSON_THROW_ON_ERROR;

/**
 * @see GuestSuggester
 */
final class GuestSuggesterTest extends TestCase
{
    /**
     * @param array<string, mixed> $responseBody
     */
    private function buildSuggesterAnswering(array $responseBody, ?RequestInterface &$sentRequest): GuestSuggester
    {
        $answerRequest = static function (RequestInterface $request) use (
            $responseBody,
            &$sentRequest,
        ): ResponseInterface {
            $sentRequest = $request;

            return new Response(
                200,
                [
                    'Content-Type' => 'application/json',
                    Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
                ],
                json_encode($responseBody, JSON_THROW_ON_ERROR),
            );
        };

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback($answerRequest);

        return new GuestSuggester(
            ClientBuilder::create()->setHttpClient($httpClient)->build(),
            new IndexNameFactory(''),
        );
    }

    public function testTheQueryCompletesTheNameAndAsksForNothingItWillNotShow(): void
    {
        $sentRequest = null;

        $this->buildSuggesterAnswering(['hits' => ['hits' => []]], $sentRequest)->suggest('tes', 7);

        self::assertInstanceOf(RequestInterface::class, $sentRequest);
        self::assertSame('/guests/_search', $sentRequest->getUri()->getPath());
        self::assertJsonStringEqualsJsonString(
            '{"size":7,"_source":["name","email"],"query":{"multi_match":{'
                . '"query":"tes","type":"bool_prefix",'
                . '"fields":["name.suggest","name.suggest._2gram","name.suggest._3gram"]'
                . '}}}',
            strval($sentRequest->getBody()),
        );
    }

    public function testTheSuggestionTakesItsIdFromTheDocumentIdAndTheRestFromTheSource(): void
    {
        $sentRequest = null;

        $suggestions = $this->buildSuggesterAnswering([
            'hits' => [
                'hits' => [
                    [
                        '_id' => '01M1CEXXTGKAFBCS4Z5D5VWAGP',
                        '_source' => [
                            'name' => 'Test Guest',
                            'email' => 'test@test.com',
                        ],
                    ],
                ],
            ],
        ], $sentRequest)->suggest('tes', 7);

        self::assertCount(1, $suggestions);
        self::assertSame('01M1CEXXTGKAFBCS4Z5D5VWAGP', $suggestions[0]->id);
        self::assertSame('Test Guest', $suggestions[0]->name);
        self::assertSame('test@test.com', $suggestions[0]->email);
    }
}
