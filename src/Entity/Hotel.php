<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\CreatedAtLifecycleCallbacksTrait;
use App\Entity\Trait\UpdatedAtLifecycleCallbacksTrait;
use App\Repository\HotelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: HotelRepository::class)]
#[ORM\HasLifecycleCallbacks]
final class Hotel
{
    use CreatedAtLifecycleCallbacksTrait;
    use UpdatedAtLifecycleCallbacksTrait;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    private Ulid $id;

    #[ORM\Column(length: 16, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $chain;

    #[ORM\Column(length: 255)]
    private string $city;

    public function __construct(?Ulid $id = null)
    {
        $this->id = $id ?? new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getChain(): string
    {
        return $this->chain;
    }

    public function setChain(string $chain): static
    {
        $this->chain = $chain;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }
}
