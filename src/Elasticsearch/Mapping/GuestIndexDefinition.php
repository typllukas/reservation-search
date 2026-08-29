<?php

declare(strict_types=1);

namespace App\Elasticsearch\Mapping;

use App\Elasticsearch\DocumentFactory\GuestDocumentFactory;
use App\Entity\Guest;
use App\Enum\IndexAlias;
use App\Repository\GuestRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Symfony\Component\Uid\Ulid;

use function array_map;

/**
 * @phpstan-import-type GuestDocument from GuestDocumentFactory
 */
final readonly class GuestIndexDefinition implements IndexDefinitionInterface
{
    private const int HYDRATED_GUEST_LIMIT = 1000;

    public function __construct(
        private GuestRepository $guestRepository,
        private GuestDocumentFactory $guestDocumentFactory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getAlias(): IndexAlias
    {
        return IndexAlias::GUESTS;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return CzechAnalysis::SETTINGS;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMapping(): array
    {
        return [
            'dynamic' => 'strict',
            'properties' => [
                'name' => [
                    'type' => 'text',
                    'analyzer' => 'folded_words',
                    'index' => false,
                    'fields' => [
                        'suggest' => [
                            'type' => 'search_as_you_type',
                            'analyzer' => 'folded_words',
                        ],
                    ],
                ],
                'email' => [
                    'type' => 'keyword',
                    'index' => false,
                ],
                'phone' => [
                    'type' => 'keyword',
                    'index' => false,
                ],
                'phone_digits' => [
                    'type' => 'keyword',
                    'index' => false,
                ],
            ],
        ];
    }

    /**
     * @return Generator<string, GuestDocument>
     */
    public function iterateDocuments(): Generator
    {
        yield from $this->buildDocumentsFrom($this->guestRepository->iterateAll());
    }

    /**
     * @return array<int, string>
     */
    public function findNewestDocumentIds(int $limit): array
    {
        return array_map(
            static fn (Ulid $id): string => $id->toBase32(),
            $this->guestRepository->findNewestIds($limit),
        );
    }

    /**
     * Guests are never deleted.
     *
     * @return Generator<Ulid>
     */
    public function iterateIdsOfDocumentsDeletedSince(DateTimeImmutable $since): Generator
    {
        yield from [];
    }

    /**
     * @return Generator<string, GuestDocument>
     */
    public function iterateDocumentsChangedSince(DateTimeImmutable $since): Generator
    {
        yield from $this->buildDocumentsFrom($this->guestRepository->iterateChangedSince($since));
    }

    /**
     * @see ReservationIndexDefinition::buildDocumentsFrom() for the clear()
     *
     * @param iterable<Guest> $guests
     *
     * @return iterable<string, GuestDocument>
     */
    private function buildDocumentsFrom(iterable $guests): iterable
    {
        $hydratedGuests = 0;

        foreach ($guests as $guest) {
            yield $guest->getId()->toBase32() => $this->guestDocumentFactory->build($guest);

            ++$hydratedGuests;
            if ($hydratedGuests % self::HYDRATED_GUEST_LIMIT !== 0) {
                continue;
            }

            $this->entityManager->clear();
        }
    }
}
