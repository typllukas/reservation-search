<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\CreatedAtLifecycleCallbacksTrait;
use App\Entity\Trait\UpdatedAtLifecycleCallbacksTrait;
use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\HasLifecycleCallbacks]
// no query reads this, bench.sh times a filtered list against it
#[ORM\Index(name: 'idx_hotel_arrival_status', fields: ['hotel', 'arrival', 'status'])]
final class Reservation
{
    use CreatedAtLifecycleCallbacksTrait;
    use UpdatedAtLifecycleCallbacksTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 16, unique: true)]
    private string $number;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Guest $guest;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Hotel $hotel;

    #[ORM\Column(length: 16, enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    #[ORM\Column(length: 16, enumType: ReservationSource::class)]
    private ReservationSource $source;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $arrival;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $departure;

    /**
     * Haléře, see ReservationRoom::$price.
     */
    #[ORM\Column]
    private int $totalPrice;

    #[ORM\Column]
    private bool $paid;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, ReservationRoom> */
    #[ORM\OneToMany(targetEntity: ReservationRoom::class, mappedBy: 'reservation')]
    private Collection $rooms;

    public function __construct(?Ulid $id = null)
    {
        $this->id = $id ?? new Ulid();
        $this->rooms = new ArrayCollection();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function setGuest(Guest $guest): static
    {
        $this->guest = $guest;

        return $this;
    }

    public function getHotel(): Hotel
    {
        return $this->hotel;
    }

    public function setHotel(Hotel $hotel): static
    {
        $this->hotel = $hotel;

        return $this;
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function setStatus(ReservationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSource(): ReservationSource
    {
        return $this->source;
    }

    public function setSource(ReservationSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getArrival(): DateTimeImmutable
    {
        return $this->arrival;
    }

    public function setArrival(DateTimeImmutable $arrival): static
    {
        $this->arrival = $arrival;

        return $this;
    }

    public function getDeparture(): DateTimeImmutable
    {
        return $this->departure;
    }

    public function setDeparture(DateTimeImmutable $departure): static
    {
        $this->departure = $departure;

        return $this;
    }

    public function getNightCount(): int
    {
        return $this->arrival->diff($this->departure)->days;
    }

    public function getTotalPrice(): int
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(int $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function setPaid(bool $paid): static
    {
        $this->paid = $paid;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /**
     * @return Collection<int, ReservationRoom>
     */
    public function getRooms(): Collection
    {
        return $this->rooms;
    }

    public function addRoom(ReservationRoom $room): static
    {
        if (!$this->rooms->contains($room)) {
            $this->rooms->add($room);
            $room->setReservation($this);
        }

        return $this;
    }
}
