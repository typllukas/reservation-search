<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeletedReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A tombstone: the reindex catches up changes by updated_at, and a deleted row is in no such query.
 */
#[ORM\Entity(repositoryClass: DeletedReservationRepository::class)]
#[ORM\Index(name: 'idx_deleted_at', fields: ['deletedAt'])]
final class DeletedReservation
{
    /**
     * The id has to be the deleted reservation's own, the index catch-up deletes by it. Rows are
     * written by DeletedReservationRepository::insertTombstones(), so nothing constructs this.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UlidType::NAME)]
        private Ulid $id,
        #[ORM\Column]
        private DateTimeImmutable $deletedAt,
    ) {
    }

    public function getId(): Ulid
    {
        return $this->id;
    }
}
