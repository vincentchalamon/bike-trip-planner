<?php

declare(strict_types=1);

namespace App\Weather;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps WMO weather codes to icon slugs and localized descriptions, and wind
 * degrees to compass points. Single source shared by the forecast derivation
 * (the frontend mirrors the description buckets in `core/` for per-hour labels).
 */
final readonly class WmoWeatherMapper
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function toIcon(int $code): string
    {
        return match (true) {
            0 === $code => '01d',
            1 === $code => '02d',
            2 === $code => '03d',
            3 === $code => '04d',
            \in_array($code, [45, 48], true) => '50d',
            $code >= 51 && $code <= 57 => '09d',
            $code >= 61 && $code <= 67 => '10d',
            $code >= 71 && $code <= 77 => '13d',
            $code >= 80 && $code <= 82 => '09d',
            \in_array($code, [85, 86], true) => '13d',
            $code >= 95 && $code <= 99 => '11d',
            default => '01d',
        };
    }

    public function toDescription(int $code, string $locale): string
    {
        $key = match (true) {
            0 === $code => 'weather.clear_sky',
            1 === $code => 'weather.mainly_clear',
            2 === $code => 'weather.partly_cloudy',
            3 === $code => 'weather.overcast',
            \in_array($code, [45, 48], true) => 'weather.fog',
            $code >= 51 && $code <= 57 => 'weather.drizzle',
            $code >= 61 && $code <= 67 => 'weather.rain',
            $code >= 71 && $code <= 77 => 'weather.snow',
            $code >= 80 && $code <= 82 => 'weather.rain_showers',
            \in_array($code, [85, 86], true) => 'weather.snow_showers',
            $code >= 95 && $code <= 99 => 'weather.thunderstorm',
            default => 'weather.unknown',
        };

        return $this->translator->trans($key, [], 'alerts', $locale);
    }

    public function degToDirection(int $deg): string
    {
        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];

        return $directions[(int) round($deg / 45) % 8];
    }
}
