<?php

declare(strict_types=1);

namespace App\Enum;

enum ReservationSource: string
{
    case TRAVEL_AGENCY = 'travel_agency';
    case PHONE = 'phone';
    case WEB = 'web';
}
