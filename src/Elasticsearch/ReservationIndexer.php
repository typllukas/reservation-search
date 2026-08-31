<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DocumentFactory\ReservationDocumentFactory;
use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Entity\Reservation;
use App\Enum\IndexAlias;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\Uid\Ulid;

use function count;
use function sprintf;

final readonly class ReservationIndexer
{
    public function __construct(
        private Client $client,
        private ReservationDocumentFactory $reservationDocumentFactory,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @throws SearchUnavailableException
     */
    public function index(Reservation $reservation): void
    {
        try {
            $this->client->index([
                'index' => $this->indexNameFactory->build(IndexAlias::RESERVATIONS),
                'id' => $reservation->getId()->toBase32(),
                'refresh' => 'wait_for',
                'body' => $this->reservationDocumentFactory->build($reservation),
            ]);
        } catch (ElasticsearchException | TransportException $exception) {
            throw new SearchUnavailableException('Writing to the search index failed.', previous: $exception);
        }
    }

    /**
     * No refresh: nobody is waiting on the purge.
     *
     * @param array<int, Ulid> $reservationIds
     *
     * @throws SearchUnavailableException|BulkIndexingFailedException
     */
    public function deleteByIds(array $reservationIds): void
    {
        if ($reservationIds === []) {
            return;
        }

        $operations = [];
        foreach ($reservationIds as $reservationId) {
            $operations[] = [
                'delete' => [
                    '_index' => $this->indexNameFactory->build(IndexAlias::RESERVATIONS),
                    '_id' => $reservationId->toBase32(),
                ],
            ];
        }

        try {
            $failures = ResponseBody::findBulkFailures(
                ResponseBody::read($this->client->bulk(['body' => $operations])),
            );
        } catch (ElasticsearchException | TransportException $exception) {
            throw new SearchUnavailableException('Deleting from the search index failed.', previous: $exception);
        }

        if ($failures !== []) {
            throw new BulkIndexingFailedException(sprintf(
                '%d documents could not be removed from the index. First error: %s',
                count($failures),
                $failures[0],
            ));
        }
    }
}
