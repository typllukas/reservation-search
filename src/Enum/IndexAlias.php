<?php

declare(strict_types=1);

namespace App\Enum;

enum IndexAlias: string
{
    case RESERVATIONS = 'reservations';
    case GUESTS = 'guests';
}
