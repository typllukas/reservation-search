<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ReservationSearchInput;
use App\DTO\ReservationSearchResult;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DataTransformer\ArrayToReservationSearchResult;
use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Enum\IndexAlias;
use App\Exception\InvalidSearchCursorException;
use App\Helper\MixedToInteger;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;

final readonly class ReservationSearcher
{
    public function __construct(
        private Client $client,
        private ReservationSearchQueryFactory $reservationSearchQueryFactory,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @throws InvalidSearchCursorException
     * @throws ResultWindowExceededException
     * @throws SearchUnavailableException
     */
    public function search(ReservationSearchInput $input): ReservationSearchResult
    {
        $body = $this->reservationSearchQueryFactory->build($input);

        try {
            $response = ResponseBody::read($this->client->search([
                'index' => $this->indexNameFactory->build(IndexAlias::RESERVATIONS),
                'body' => $body,
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            throw new SearchUnavailableException('Search is unavailable.', previous: $exception);
        }

        return ArrayToReservationSearchResult::transform(
            $response,
            $input->sort,
            MixedToInteger::transformStrict($body['from'] ?? 0),
            $input->size,
        );
    }
}
