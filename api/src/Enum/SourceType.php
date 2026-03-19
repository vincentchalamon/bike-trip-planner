<?php

declare(strict_types=1);

namespace App\Enum;

enum SourceType: string
{
    case KOMOOT_TOUR = 'komoot_tour';
    case KOMOOT_COLLECTION = 'komoot_collection';
case GPX_UPLOAD = 'gpx_upload';
}
