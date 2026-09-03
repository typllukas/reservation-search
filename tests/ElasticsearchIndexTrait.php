<?php

declare(strict_types=1);

namespace App\Tests;

use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;

use function implode;
use function is_string;

trait ElasticsearchIndexTrait
{
    protected static Client $elasticsearchClient;

    protected static function connectToElasticsearch(): void
    {
        $dsn = $_SERVER['ELASTICSEARCH_DSN'] ?? null;
        if (!is_string($dsn)) {
            self::fail('ELASTICSEARCH_DSN is not set, .env should carry it.');
        }

        self::$elasticsearchClient = ClientBuilder::create()
            ->setHosts([$dsn])
            ->build();

        self::requireReachableElasticsearch($dsn);
    }

    protected static function createIndex(string $indexName, IndexDefinitionInterface $indexDefinition): void
    {
        self::$elasticsearchClient->indices()
            ->create([
                'index' => $indexName,
                'body' => [
                    'settings' => $indexDefinition->getSettings(),
                    'mappings' => $indexDefinition->getMapping(),
                ],
            ]);
    }

    protected static function dropIndices(string ...$indexNames): void
    {
        self::$elasticsearchClient->indices()
            ->delete([
                'index' => implode(',', $indexNames),
                'ignore_unavailable' => true,
            ]);
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     */
    protected static function indexDocuments(array $operations): void
    {
        $response = ResponseBody::read(self::$elasticsearchClient->bulk([
            'body' => $operations,
            'refresh' => 'wait_for',
        ]));

        self::assertSame([], ResponseBody::findBulkFailures($response));
    }

    private static function requireReachableElasticsearch(string $dsn): void
    {
        try {
            self::$elasticsearchClient->info();
        } catch (ElasticsearchException | TransportException $exception) {
            // a skipped test reads as green, so CI has to fail; rethrowing keeps the host and the cause
            if (self::isRunningInCi()) {
                throw $exception;
            }

            self::markTestSkipped('Elasticsearch at ' . $dsn . ' is not responding, run make up.');
        }
    }

    private static function isRunningInCi(): bool
    {
        return ($_SERVER['CI'] ?? null) === 'true';
    }
}
