<?php

declare(strict_types=1);

namespace App\Enum;

enum RoomKind: string
{
    case SINGLE = 'single';
    case DOUBLE = 'double';
    case APARTMENT = 'apartment';
    case FAMILY = 'family';
}
