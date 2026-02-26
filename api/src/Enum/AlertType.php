<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertType: string
{
    case CRITICAL = 'critical';
    case WARNING = 'warning';
    case NUDGE = 'nudge';
}
