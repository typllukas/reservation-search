<?php

declare(strict_types=1);

namespace App\Enum;

enum ReservationSort: string
{
    case RELEVANCE = 'relevance';
    case ARRIVAL = 'arrival';
    case LAST_CHANGE = 'last_change';
}
