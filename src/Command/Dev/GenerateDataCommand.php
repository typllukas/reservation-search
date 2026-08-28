<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Repository\HotelRepository;
use App\Service\ReservationDataGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function intval;
use function is_string;
use function memory_get_peak_usage;
use function microtime;
use function preg_match;
use function sprintf;

#[AsCommand(
    name: 'reservation-search:dev:generate-data',
    description: 'Seed the database with test hotels, guests and reservations.',
)]
final class GenerateDataCommand extends Command
{
    public function __construct(
        private readonly ReservationDataGenerator $reservationDataGenerator,
        private readonly HotelRepository $hotelRepository,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'hotels',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Number of hotels',
                default: '220',
            )
            ->addOption(
                'guests',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Number of guests',
                default: '20000',
            )
            ->addOption(
                'reservations',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Number of reservations',
                default: '10000',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $hotelCount = $this->readPositiveCount($input, 'hotels');
        $guestCount = $this->readPositiveCount($input, 'guests');
        $reservationCount = $this->readPositiveCount($input, 'reservations');

        if ($hotelCount === null || $guestCount === null || $reservationCount === null) {
            $io->error('Each of --hotels, --guests and --reservations must be a whole number above zero.');

            return Command::INVALID;
        }

        if ($this->hotelRepository->count([]) > 0) {
            $io->error('The database already holds data. Run make reset, which drops the schema first.');

            return Command::FAILURE;
        }

        $startedAt = microtime(true);
        $io->writeln(sprintf(
            'Generating %d hotels, %d guests, %d reservations.',
            $hotelCount,
            $guestCount,
            $reservationCount,
        ));

        $this->reservationDataGenerator->generate(
            $hotelCount,
            $guestCount,
            $reservationCount,
            static fn (string $message) => $io->writeln($message),
        );

        $io->success(sprintf(
            'Done in %.1f s, peak memory %.0f MB.',
            microtime(true) - $startedAt,
            memory_get_peak_usage(true) / 1024 / 1024,
        ));

        return Command::SUCCESS;
    }

    private function readPositiveCount(InputInterface $input, string $optionName): ?int
    {
        $value = $input->getOption($optionName);

        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        return intval($value);
    }
}
