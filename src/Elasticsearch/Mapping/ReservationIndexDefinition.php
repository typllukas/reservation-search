<?php

declare(strict_types=1);

namespace App\Elasticsearch\Mapping;

use App\Elasticsearch\DocumentFactory\ReservationDocumentFactory;
use App\Enum\IndexAlias;
use App\Repository\DeletedReservationRepository;
use App\Repository\ReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function array_merge;

/**
 * @phpstan-import-type ReservationDocument from ReservationDocumentFactory
 */
final readonly class ReservationIndexDefinition implements IndexDefinitionInterface
{
    /**
     * Where paging by relevance stops: every shard holds from + size hits per request, so this caps how deep
     * a caller may go. Elasticsearch's default, declared so the index and the check agree.
     */
    public const int MAX_RESULT_WINDOW = 10000;

    private const int HYDRATION_BATCH_SIZE = 1000;

    public function __construct(
        private ReservationRepository $reservationRepository,
        private DeletedReservationRepository $deletedReservationRepository,
        private ReservationDocumentFactory $reservationDocumentFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getAlias(): IndexAlias
    {
        return IndexAlias::RESERVATIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return array_merge(
            ['index' => ['max_result_window' => self::MAX_RESULT_WINDOW]],
            CzechAnalysis::SETTINGS,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getMapping(): array
    {
        return [
            'dynamic' => 'strict',
            'properties' => [
                'id' => ['type' => 'keyword'], // search_after needs a unique tiebreaker, _id cannot be sorted on
                'number' => ['type' => 'keyword'],
                'status' => ['type' => 'keyword'],
                'source' => ['type' => 'keyword'],
                // a day, not a moment: the default format would also accept a datetime and shift a range bound by hours
                'arrival' => [
                    'type' => 'date',
                    'format' => 'strict_date',
                ],
                'departure' => [
                    'type' => 'date',
                    'format' => 'strict_date',
                    'index' => false,
                ],
                'nights' => [
                    'type' => 'integer',
                    'index' => false,
                ],
                'total_price' => ['type' => 'integer'],
                'paid' => [
                    'type' => 'boolean',
                    'index' => false,
                ],
                'note' => [
                    'type' => 'text',
                    'analyzer' => 'czech_folded_stems',
                    // an English note through the Czech stemmer matches only its exact word form
                    'fields' => [
                        'english' => [
                            'type' => 'text',
                            'analyzer' => 'english',
                        ],
                    ],
                ],
                'created_at' => [
                    'type' => 'date',
                    'index' => false,
                ],
                'updated_at' => ['type' => 'date'],
                'guest' => [
                    'properties' => [
                        'id' => [
                            'type' => 'keyword',
                            'index' => false,
                        ],
                        // no search_as_you_type, a frequent guest would appear once per stay; the guest index has it
                        'name' => [
                            'type' => 'text',
                            'analyzer' => 'folded_words',
                        ],
                        // keyword only; as text, example.com became its own term and matched every guest
                        'email' => ['type' => 'keyword'],
                        // display only, searching goes through phone_digits
                        'phone' => [
                            'type' => 'keyword',
                            'index' => false,
                        ],
                        'phone_digits' => ['type' => 'keyword'],
                    ],
                ],
                'hotel' => [
                    'properties' => [
                        'id' => ['type' => 'keyword'],
                        'code' => ['type' => 'keyword'],
                        'name' => [
                            'type' => 'text',
                            'analyzer' => 'folded_words',
                            'fields' => [
                                'keyword' => ['type' => 'keyword'],
                            ],
                        ],
                        'chain' => [
                            'type' => 'keyword',
                            'index' => false,
                        ],
                    ],
                ],
                // nested, not object: a "double for two" filter must not match across two different rooms
                'rooms' => [
                    'type' => 'nested',
                    'properties' => [
                        'kind' => ['type' => 'keyword'],
                        'guest_count' => [
                            'type' => 'integer',
                            'index' => false,
                        ],
                        'price' => [
                            'type' => 'integer',
                            'index' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return Generator<string, ReservationDocument>
     */
    public function iterateDocuments(): Generator
    {
        yield from $this->buildDocumentsFrom(
            $this->reservationRepository->iterateIdBatches(self::HYDRATION_BATCH_SIZE),
        );
    }

    /**
     * @return array<int, string>
     */
    public function findNewestDocumentIds(int $limit): array
    {
        return array_map(
            static fn (Ulid $id): string => $id->toBase32(),
            $this->reservationRepository->findNewestIds($limit),
        );
    }

    /**
     * @return Generator<Ulid>
     */
    public function iterateIdsOfDocumentsDeletedSince(DateTimeImmutable $since): Generator
    {
        yield from $this->deletedReservationRepository->iterateIdsOfReservationsDeletedSince($since);
    }

    /**
     * @return Generator<string, ReservationDocument>
     */
    public function iterateDocumentsChangedSince(DateTimeImmutable $since): Generator
    {
        yield from $this->buildDocumentsFrom(
            $this->reservationRepository->iterateIdBatchesForChangesSince($since, self::HYDRATION_BATCH_SIZE),
        );
    }

    /**
     * @param iterable<array<int, Ulid>> $idBatches
     *
     * @return iterable<string, ReservationDocument>
     */
    private function buildDocumentsFrom(iterable $idBatches): iterable
    {
        foreach ($idBatches as $ids) {
            foreach ($this->reservationRepository->findForIndexing($ids) as $reservation) {
                yield $reservation->getId()->toBase32() => $this->reservationDocumentFactory->build($reservation);
            }

            $this->entityManager->clear();
        }
    }
}
