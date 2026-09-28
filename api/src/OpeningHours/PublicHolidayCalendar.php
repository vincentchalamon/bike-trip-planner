<?php

declare(strict_types=1);

namespace App\OpeningHours;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yasumi\ProviderInterface;
use Yasumi\Yasumi;

/**
 * Answers "is this date a public holiday in any of these countries?" for the
 * `PH` selector.
 *
 * Yasumi recomputes a country's whole holiday set on every provider creation
 * (~120 µs), so providers are kept per (country, year) for the lifetime of the
 * service: a page of N POIs tagged `PH` costs one creation per country and
 * year, not 2N.
 */
final class PublicHolidayCalendar
{
    /** @var array<string, ProviderInterface> keyed by `<country>-<year>` */
    private array $providers = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Best effort: a provider that cannot be built makes the date a working day.
     *
     * @param list<string> $countryCodes ISO 3166-1 alpha-2 codes (`FR`, `BE`)
     */
    public function isHoliday(\DateTimeImmutable $date, array $countryCodes): bool
    {
        try {
            $year = (int) $date->format('Y');
            $needle = new \DateTime($date->format('Y-m-d'), $date->getTimezone());

            foreach ($countryCodes as $countryCode) {
                $provider = $this->providers[$countryCode.'-'.$year] ??= Yasumi::createByISO3166_2($countryCode, $year);

                if ($provider->isHoliday($needle)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $throwable) {
            $this->logger->info('Failed to compute public holiday', ['error' => $throwable->getMessage()]);

            return false;
        }
    }
}
