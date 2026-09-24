<?php

declare(strict_types=1);

namespace App\Command;

use App\Elasticsearch\Exception\BulkIndexingFailedException;
use App\Elasticsearch\Mapping\GuestIndexDefinition;
use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use App\Elasticsearch\Mapping\ReservationIndexDefinition;
use App\Elasticsearch\Reindexer;
use App\Enum\IndexAlias;
use App\Helper\MixedToString;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_column;
use function implode;
use function memory_get_peak_usage;
use function sprintf;

#[AsCommand(
    name: 'reservation-search:index:reindex',
    description: 'Rebuild the index into a new version and switch the alias onto it.',
)]
final class ReindexCommand extends Command
{
    private const string ALL_INDEXES = 'all';

    public function __construct(
        private readonly Reindexer $reindexer,
        private readonly ReservationIndexDefinition $reservationIndexDefinition,
        private readonly GuestIndexDefinition $guestIndexDefinition,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument(
            'index',
            mode: InputArgument::OPTIONAL,
            description: sprintf('Which index: %s, or %s', $this->formatIndexNames(), self::ALL_INDEXES),
            default: self::ALL_INDEXES,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requestedIndexName = MixedToString::transformStrict($input->getArgument('index'));

        $indexDefinitions = $this->resolveDefinitionsFor($requestedIndexName);
        if ($indexDefinitions === []) {
            $io->error(sprintf(
                'Unknown index "%s". Use %s or %s.',
                $requestedIndexName,
                $this->formatIndexNames(),
                self::ALL_INDEXES,
            ));

            return Command::INVALID;
        }

        foreach ($indexDefinitions as $indexDefinition) {
            $io->section($indexDefinition->getAlias()->value);
            try {
                $this->reindexer->reindex(
                    $indexDefinition,
                    static fn (string $message) => $io->writeln($message),
                );
            } catch (ElasticsearchException | TransportException | BulkIndexingFailedException $exception) {
                $io->error(sprintf(
                    'Elasticsearch refused the reindex of %s: %s',
                    $indexDefinition->getAlias()->value,
                    $exception->getMessage(),
                ));

                return Command::FAILURE;
            }
        }

        $io->writeln(sprintf('Peak memory: %.1f MB', memory_get_peak_usage(true) / 1024 / 1024));

        return Command::SUCCESS;
    }

    /**
     * @return array<IndexDefinitionInterface>
     */
    private function resolveDefinitionsFor(string $requestedIndexName): array
    {
        if ($requestedIndexName === self::ALL_INDEXES) {
            return [$this->guestIndexDefinition, $this->reservationIndexDefinition];
        }

        return match (IndexAlias::tryFrom($requestedIndexName)) {
            IndexAlias::RESERVATIONS => [$this->reservationIndexDefinition],
            IndexAlias::GUESTS => [$this->guestIndexDefinition],
            null => [],
        };
    }

    private function formatIndexNames(): string
    {
        return implode(', ', array_column(IndexAlias::cases(), 'value'));
    }
}
