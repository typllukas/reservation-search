<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use DateTimeImmutable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Ulid;

use function array_keys;
use function count;
use function in_array;
use function intval;
use function max;
use function preg_match;
use function sprintf;

/**
 * Not Elasticsearch's _reindex: the documents are read again from MariaDB.
 */
final readonly class Reindexer
{
    public const int BULK_BATCH_SIZE = 1000;

    /**
     * A single-node dev cluster cannot place a replica and stays yellow, so the env var.
     */
    public function __construct(
        private Client $client,
        private IndexNameFactory $indexNameFactory,
        #[Autowire(env: 'int:ELASTICSEARCH_REPLICAS')]
        private int $replicaCount,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     *
     * @throws ElasticsearchException|TransportException|BulkIndexingFailedException
     */
    public function reindex(IndexDefinitionInterface $indexDefinition, callable $reportProgress): void
    {
        // taken before the fill, a write during it would otherwise be lost
        $fillStartedAt = new DateTimeImmutable();

        $alias = $this->indexNameFactory->build($indexDefinition->getAlias());
        $targetIndexName = $this->buildNextIndexName($alias);

        $reportProgress(sprintf('Creating %s', $targetIndexName));
        $this->createIndex($targetIndexName, $indexDefinition);

        // No point rebuilding the search structure or replicating after every batch.
        $this->tuneForBulkLoad($targetIndexName);
        $indexed = $this->fill($targetIndexName, $indexDefinition->iterateDocuments(), $reportProgress);
        $this->tuneForSearch($targetIndexName);

        /**
         * Two passes around the switch, the second picks up what arrived during the first. Deletions
         * come from the tombstones: the delete message went to the alias, which still pointed at the old index.
         */
        $firstCatchUpStartedAt = new DateTimeImmutable();
        $changesBeforeSwitch = $this->fill(
            $targetIndexName,
            $indexDefinition->iterateDocumentsChangedSince($fillStartedAt),
            $reportProgress,
        );
        $deletionsBeforeSwitch = $this->removeDeleted(
            $targetIndexName,
            $indexDefinition->iterateIdsOfDocumentsDeletedSince($fillStartedAt),
        );

        $reportProgress(sprintf('Switching the alias %s onto %s', $alias, $targetIndexName));
        $supersededIndices = $this->findIndicesForAlias($alias);
        $this->switchAlias($alias, $supersededIndices, $targetIndexName);

        $changesAfterSwitch = $this->fill(
            $targetIndexName,
            $indexDefinition->iterateDocumentsChangedSince($firstCatchUpStartedAt),
            $reportProgress,
        );
        $deletionsAfterSwitch = $this->removeDeleted(
            $targetIndexName,
            $indexDefinition->iterateIdsOfDocumentsDeletedSince($firstCatchUpStartedAt),
        );

        $reportProgress(sprintf(
            'Done: %d documents, caught up %d changes and %d deletions before the switch, '
                . '%d changes and %d deletions after it',
            $indexed,
            $changesBeforeSwitch,
            $deletionsBeforeSwitch,
            $changesAfterSwitch,
            $deletionsAfterSwitch,
        ));

        $this->dropIndicesExcept($alias, [$targetIndexName, ...$supersededIndices], $reportProgress);
    }

    /**
     * Numbered from every existing <alias>_v* index: an interrupted run leaves one without the
     * alias, and numbering from the alias would collide with it.
     */
    private function buildNextIndexName(string $alias): string
    {
        $version = 0;
        foreach ($this->findVersionedIndexNames($alias) as $indexName) {
            if (preg_match('/_v(\d+)$/', $indexName, $matches) !== 1) {
                continue;
            }

            $version = max($version, intval($matches[1]));
        }

        return sprintf('%s_v%d', $alias, $version + 1);
    }

    /**
     * A wildcard that matches nothing returns an empty response, no 404. filter_path drops the
     * mapping.
     *
     * @return array<int, string>
     */
    private function findVersionedIndexNames(string $alias): array
    {
        $response = $this->client->indices()
            ->get([
                'index' => $alias . '_v*',
                'filter_path' => '*.aliases',
            ]);

        return array_keys(ResponseBody::read($response));
    }

    /**
     * getAlias on a missing name is a 404; ignore_unavailable covers index names only.
     *
     * @return array<int, string>
     */
    private function findIndicesForAlias(string $alias): array
    {
        try {
            $response = $this->client->indices()->getAlias(['name' => $alias]);
        } catch (ElasticsearchException $exception) {
            if ($exception->getCode() === 404) {
                return [];
            }

            throw $exception;
        }

        return array_keys(ResponseBody::read($response));
    }

    private function createIndex(string $indexName, IndexDefinitionInterface $indexDefinition): void
    {
        $this->client->indices()
            ->create([
                'index' => $indexName,
                'body' => [
                    'settings' => $indexDefinition->getSettings(),
                    'mappings' => $indexDefinition->getMapping(),
                ],
            ]);
    }

    private function tuneForBulkLoad(string $indexName): void
    {
        $this->client->indices()
            ->putSettings([
                'index' => $indexName,
                'body' => [
                    'index' => [
                        'refresh_interval' => -1,
                        'number_of_replicas' => 0,
                    ],
                ],
            ]);
    }

    private function tuneForSearch(string $indexName): void
    {
        $this->client->indices()
            ->putSettings([
                'index' => $indexName,
                'body' => [
                    'index' => [
                        'refresh_interval' => '1s',
                        'number_of_replicas' => $this->replicaCount,
                    ],
                ],
            ]);
        $this->client->indices()->refresh(['index' => $indexName]);
    }

    /**
     * @param iterable<string, array<string, mixed>> $documents
     * @param callable(string): void $reportProgress
     */
    private function fill(string $indexName, iterable $documents, callable $reportProgress): int
    {
        $operations = [];
        $inBatch = 0;
        $total = 0;

        foreach ($documents as $id => $document) {
            $operations[] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => $id,
                ],
            ];
            $operations[] = $document;
            ++$inBatch;

            if ($inBatch !== self::BULK_BATCH_SIZE) {
                continue;
            }

            $total += $this->flushBatch($operations, $inBatch, $total, $reportProgress);
            $operations = [];
            $inBatch = 0;
        }

        if ($inBatch > 0) {
            $total += $this->flushBatch($operations, $inBatch, $total, $reportProgress);
        }

        return $total;
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @param callable(string): void $reportProgress
     */
    private function flushBatch(array $operations, int $inBatch, int $alreadyDone, callable $reportProgress): int
    {
        $response = ResponseBody::read($this->client->bulk(['body' => $operations]));

        $failures = ResponseBody::findBulkFailures($response);
        if ($failures !== []) {
            throw new BulkIndexingFailedException(sprintf(
                'A batch of %d documents: %d were not written. First error: %s',
                $inBatch,
                count($failures),
                $failures[0],
            ));
        }

        $reportProgress(sprintf('batch of %d documents (%d in total)', $inBatch, $alreadyDone + $inBatch));

        return $inBatch;
    }

    /**
     * @param iterable<Ulid> $deletedIds
     *
     * @throws ElasticsearchException|TransportException
     */
    private function removeDeleted(string $indexName, iterable $deletedIds): int
    {
        $operations = [];
        $removedDocuments = 0;

        foreach ($deletedIds as $deletedId) {
            $operations[] = [
                'delete' => [
                    '_index' => $indexName,
                    '_id' => $deletedId->toBase32(),
                ],
            ];
            ++$removedDocuments;

            if (count($operations) !== self::BULK_BATCH_SIZE) {
                continue;
            }

            $this->flushDeletions($operations);
            $operations = [];
        }

        $this->flushDeletions($operations);

        return $removedDocuments;
    }

    /**
     * Without this the reindex would report the deletions caught up and leave the ghosts behind.
     *
     * @param array<int, array<string, mixed>> $operations
     *
     * @throws ElasticsearchException|TransportException|BulkIndexingFailedException
     */
    private function flushDeletions(array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $failures = ResponseBody::findBulkFailures(
            ResponseBody::read($this->client->bulk(['body' => $operations])),
        );

        if ($failures !== []) {
            throw new BulkIndexingFailedException(sprintf(
                'Deletion catch-up: %d documents could not be removed. First error: %s',
                count($failures),
                $failures[0],
            ));
        }
    }

    /**
     * Remove and add in one call: there is no moment at which the alias points nowhere.
     *
     * @param array<int, string> $currentIndices the indices the alias currently stands for
     */
    private function switchAlias(string $alias, array $currentIndices, string $targetIndexName): void
    {
        $actions = [];
        foreach ($currentIndices as $indexName) {
            $actions[] = [
                'remove' => [
                    'index' => $indexName,
                    'alias' => $alias,
                ],
            ];
        }

        $actions[] = [
            'add' => [
                'index' => $targetIndexName,
                'alias' => $alias,
            ],
        ];

        $this->client->indices()
            ->updateAliases([
                'body' => ['actions' => $actions],
            ]);
    }

    /**
     * The previous version stays as a way back: switching the alias back takes a second, a rebuild
     * minutes.
     *
     * @param array<int, string> $indicesToKeep
     * @param callable(string): void $reportProgress
     */
    private function dropIndicesExcept(string $alias, array $indicesToKeep, callable $reportProgress): void
    {
        foreach ($this->findVersionedIndexNames($alias) as $indexName) {
            if (in_array($indexName, $indicesToKeep, true)) {
                continue;
            }

            $this->client->indices()->delete(['index' => $indexName]);
            $reportProgress(sprintf('Dropping the superseded index %s', $indexName));
        }
    }
}
