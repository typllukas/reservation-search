<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\GuestSuggestion;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DataTransformer\ArrayToGuestSuggestions;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Enum\IndexAlias;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;

/**
 * search_as_you_type builds the ._2gram and ._3gram sub-fields itself.
 */
final readonly class GuestSuggester
{
    public function __construct(
        private Client $client,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @return array<int, GuestSuggestion>
     *
     * @throws SearchUnavailableException
     */
    public function suggest(string $text, int $size): array
    {
        try {
            $response = ResponseBody::read($this->client->search([
                'index' => $this->indexNameFactory->build(IndexAlias::GUESTS),
                'body' => [
                    'size' => $size,
                    '_source' => ['name', 'email'],
                    'query' => [
                        'multi_match' => [
                            'query' => $text,
                            'type' => 'bool_prefix',
                            'fields' => ['name.suggest', 'name.suggest._2gram', 'name.suggest._3gram'],
                        ],
                    ],
                ],
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            throw new SearchUnavailableException('Suggestions are unavailable.', previous: $exception);
        }

        return ArrayToGuestSuggestions::transform($response);
    }
}
