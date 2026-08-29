<?php

declare(strict_types=1);

namespace App\Elasticsearch\Mapping;

use App\Enum\IndexAlias;
use DateTimeImmutable;
use Generator;
use Symfony\Component\Uid\Ulid;

interface IndexDefinitionInterface
{
    public function getAlias(): IndexAlias;

    /** @return array<string, mixed> */
    public function getSettings(): array;

    /** @return array<string, mixed> */
    public function getMapping(): array;

    /** @return Generator<string, array<string, mixed>> */
    public function iterateDocuments(): Generator;

    /** @return array<int, string> */
    public function findNewestDocumentIds(int $limit): array;

    /** @return Generator<Ulid> */
    public function iterateIdsOfDocumentsDeletedSince(DateTimeImmutable $since): Generator;

    /** @return Generator<string, array<string, mixed>> */
    public function iterateDocumentsChangedSince(DateTimeImmutable $since): Generator;
}
