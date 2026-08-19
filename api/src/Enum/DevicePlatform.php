<?php

declare(strict_types=1);

namespace App\Enum;

enum DevicePlatform: string
{
    case ANDROID = 'android';
    case IOS = 'ios';
}
