<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GuestSuggestInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 200)]
        public string $text = '',
        #[Assert\Range(min: 1, max: 20)]
        public int $size = 8,
    ) {
    }
}
