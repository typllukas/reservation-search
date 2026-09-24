<?php

declare(strict_types=1);

namespace App\Command\Cron;

use App\Service\ReservationPurger;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

use function is_string;
use function sprintf;

#[AsCommand(
    name: 'reservation-search:cron:purge-reservations',
    description: 'Delete reservations after their retention period has ended.',
)]
final class PurgeReservationsCommand extends Command
{
    public function __construct(
        private readonly ReservationPurger $reservationPurger,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(
            'before',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Deletes reservations that departed before this datetime, for example "midnight -5 years".',
        );
        $this->addOption(
            'dry-run',
            mode: InputOption::VALUE_NONE,
            description: 'Report what would be deleted and delete nothing.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $before = $input->getOption('before');
        if (!is_string($before)) {
            $io->error('Missing --before. Give a datetime string, for example --before="midnight -5 years".');

            return Command::INVALID;
        }

        try {
            $departedBefore = new DateTimeImmutable($before);
        } catch (Throwable) {
            $io->error(sprintf('"%s" is not a datetime string PHP understands.', $before));

            return Command::INVALID;
        }

        // a missing minus turns the boundary into the future, where every reservation qualifies
        if ($departedBefore >= new DateTimeImmutable()) {
            $io->error(sprintf(
                '--before resolves to %s, which is not in the past. Point it at a past datetime, for example '
                    . '"midnight -5 years".',
                $departedBefore->format('Y-m-d H:i'),
            ));

            return Command::INVALID;
        }

        $dryRun = $input->getOption('dry-run') === true;
        if ($dryRun) {
            $io->warning('Dry run: nothing will be deleted and the index will not be told anything.');
        }

        $io->writeln(sprintf(
            '%s reservations that departed before %s.',
            $dryRun ? 'Counting' : 'Deleting',
            $departedBefore->format('Y-m-d'),
        ));

        try {
            $purgedReservations = $this->reservationPurger->purge(
                $departedBefore,
                static fn (string $message) => $io->writeln($message),
                $dryRun,
            );
        } catch (HandlerFailedException $exception) {
            $io->error(sprintf(
                'The rows are deleted, but the index never heard about it: %s',
                ($exception->getPrevious() ?? $exception)->getMessage(),
            )); // the sync transport wraps handler exceptions, so catching SearchUnavailableException never fires

            return Command::FAILURE;
        }

        $io->success(sprintf(
            $dryRun ? 'Would delete %d reservations.' : 'Deleted %d reservations.',
            $purgedReservations,
        ));

        return Command::SUCCESS;
    }
}
