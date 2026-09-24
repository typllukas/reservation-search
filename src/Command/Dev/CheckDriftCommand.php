<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\IndexNameFactory;
use App\Elasticsearch\Mapping\GuestIndexDefinition;
use App\Elasticsearch\Mapping\ReservationIndexDefinition;
use App\Enum\IndexAlias;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'reservation-search:dev:check-drift',
    description: 'Check the newest database rows and report those the index does not hold under the same id.',
)]
final class CheckDriftCommand extends Command
{
    private const int NEWEST_ROWS_CHECKED = 20;

    public function __construct(
        private readonly Client $client,
        private readonly IndexNameFactory $indexNameFactory,
        private readonly ReservationIndexDefinition $reservationIndexDefinition,
        private readonly GuestIndexDefinition $guestIndexDefinition,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $missingCount = 0;

        foreach ([$this->reservationIndexDefinition, $this->guestIndexDefinition] as $indexDefinition) {
            $alias = $indexDefinition->getAlias();
            $ids = $indexDefinition->findNewestDocumentIds(self::NEWEST_ROWS_CHECKED);
            if ($ids === []) {
                $io->writeln(sprintf('%s: the database holds no rows to compare', $alias->value));
                continue;
            }

            try {
                $missingIds = $this->findIdsMissingFromIndex($alias, $ids);
            } catch (SearchUnavailableException) {
                $io->writeln('Elasticsearch is not answering, so drift cannot be checked');

                return Command::FAILURE;
            }

            foreach ($missingIds as $missingId) {
                $io->writeln(sprintf('%s: %s is in the database and not in the index', $alias->value, $missingId));
                ++$missingCount;
            }
        }

        if ($missingCount > 0) {
            $io->writeln('Run make reindex');

            return Command::FAILURE;
        }

        $io->writeln('Drift: every checked row is in the index under the same id');

        return Command::SUCCESS;
    }

    /**
     * @param array<int, string> $ids
     *
     * @return array<int, string>
     *
     * @throws SearchUnavailableException
     */
    private function findIdsMissingFromIndex(IndexAlias $alias, array $ids): array
    {
        try {
            $response = ResponseBody::read($this->client->mget([
                'index' => $this->indexNameFactory->build($alias),
                'body' => ['ids' => $ids],
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            // no alias before the first reindex, so every checked row counts as missing
            if ($exception instanceof ClientResponseException && $exception->getCode() === 404) {
                return $ids;
            }

            throw new SearchUnavailableException('Elasticsearch is not answering.', previous: $exception);
        }

        $missingIds = [];
        foreach (ResponseBody::readArray($response, 'docs') as $documentOrMiss) {
            $document = ResponseBody::narrowToArray($documentOrMiss);
            if (($document['found'] ?? null) === true) {
                continue;
            }

            $missingIds[] = ResponseBody::readStringStrict($document, '_id');
        }

        return $missingIds;
    }
}
