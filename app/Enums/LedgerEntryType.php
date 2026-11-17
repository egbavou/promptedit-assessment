<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';
}
