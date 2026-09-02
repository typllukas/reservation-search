<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ReservationScoreExplanation;
use App\DTO\ReservationSearchInput;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DataTransformer\ArrayToReservationScoreExplanation;
use App\Elasticsearch\Exception\ReservationNotIndexedException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Enum\IndexAlias;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\Uid\Ulid;

use function str_contains;
use function strval;

final readonly class ReservationScoreExplainer
{
    public function __construct(
        private Client $client,
        private ReservationSearchQueryFactory $reservationSearchQueryFactory,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @throws SearchUnavailableException|ReservationNotIndexedException
     */
    public function explain(Ulid $reservationId, ReservationSearchInput $input): ReservationScoreExplanation
    {
        try {
            $response = ResponseBody::read($this->client->explain([
                'index' => $this->indexNameFactory->build(IndexAlias::RESERVATIONS),
                'id' => $reservationId->toBase32(),
                'body' => [
                    'query' => $this->reservationSearchQueryFactory->buildQuery($input),
                ],
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            // _explain answers 404 for a document missing from the index
            if ($exception->getCode() === 404 && !$this->isIndexMissing($exception)) {
                throw new ReservationNotIndexedException('No document in the index.', previous: $exception);
            }

            throw new SearchUnavailableException('Search is unavailable.', previous: $exception);
        }

        return ArrayToReservationScoreExplanation::transform($response);
    }

    /**
     * The alias does not exist until the reindexer has run, so its 404 is an outage and not a missing document.
     */
    private function isIndexMissing(ElasticsearchException|TransportException $exception): bool
    {
        if (!$exception instanceof ClientResponseException) {
            return false;
        }

        return str_contains(strval($exception->getResponse()->getBody()), 'index_not_found_exception');
    }
}
