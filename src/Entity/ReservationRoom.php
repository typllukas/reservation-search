<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\CreatedAtLifecycleCallbacksTrait;
use App\Enum\RoomKind;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The kind of room reserved, no particular room (assuming the hotel assigns the number on arrival).
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class ReservationRoom
{
    use CreatedAtLifecycleCallbacksTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\ManyToOne(inversedBy: 'rooms')]
    #[ORM\JoinColumn(nullable: false)]
    private Reservation $reservation;

    #[ORM\Column(length: 16, enumType: RoomKind::class)]
    private RoomKind $kind;

    #[ORM\Column]
    private int $guestCount;

    /**
     * Haléře, so 100 is 1 CZK; an integer keeps aggregation sums exact.
     */
    #[ORM\Column]
    private int $price;

    public function __construct(?Ulid $id = null)
    {
        $this->id = $id ?? new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function setReservation(Reservation $reservation): static
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getKind(): RoomKind
    {
        return $this->kind;
    }

    public function setKind(RoomKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getGuestCount(): int
    {
        return $this->guestCount;
    }

    public function setGuestCount(int $guestCount): static
    {
        $this->guestCount = $guestCount;

        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }
}
