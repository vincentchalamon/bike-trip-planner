<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCalendar;
use App\Osm\AdminBoundaryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Yasumi\Holiday;
use Yasumi\ProviderInterface;
use Yasumi\Yasumi;

/**
 * Flags stages falling on a public holiday or a Sunday (businesses may be closed).
 *
 * The countries are resolved from the route itself, via the same admin_level=2
 * ST_Covers lookup as CheckBorderCrossingHandler, and holidays are loaded for every
 * calendar year the trip spans (a trip straddling 31 December sees both years).
 * When no country can be resolved — the admin_level=2 relations may be missing from
 * a regional OSM extract — France is kept as the fallback, which is the behaviour
 * this handler had before.
 *
 * Deliberately has no `isRestDay` guard: a public holiday or a Sunday closes shops
 * and restaurants whether or not the rider pedals, and a rest day is precisely when
 * they are needed.
 */
#[AsMessageHandler]
final readonly class CheckCalendarHandler extends AbstractTripMessageHandler
{
    /**
     * Kept when the route's countries cannot be resolved: replacing a hard-coded
     * country by no country at all would be worse (no holiday detected anywhere).
     */
    private const string FALLBACK_COUNTRY_CODE = 'FR';

    private const string FALLBACK_YASUMI_LOCALE = 'en_US';

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private AdminBoundaryRepositoryInterface $adminBoundaryRepository,
        private TranslatorInterface $translator,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(CheckCalendar $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripStateManager->getRequest($tripId);
        $stages = $this->tripStateManager->getStages($tripId);

        if (!$request instanceof TripRequest || null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::CALENDAR, function () use ($tripId, $request, $stages, $locale): void {
            $startDate = $request->startDate ?? new \DateTimeImmutable('today');
            $providers = $this->resolveProviders($stages, $startDate, $locale);

            $alerts = [];

            foreach ($stages as $i => $stage) {
                $stageDate = $startDate->modify(\sprintf('+%d days', $i));
                $holiday = $this->findHoliday($providers, $stageDate);

                if ($holiday instanceof Holiday) {
                    $holidayName = $this->resolveHolidayName($holiday);
                    // Named and unnamed phrasings are the same rule, hence the same code.
                    $alerts[] = $this->buildAlert(
                        $i,
                        $stage,
                        $stageDate,
                        AlertCode::CALENDAR_PUBLIC_HOLIDAY,
                        null !== $holidayName ? 'alert.calendar.nudge' : 'alert.calendar.unnamed_nudge',
                        null !== $holidayName
                            ? ['%stage%' => $stage->dayNumber, '%holiday%' => $holidayName]
                            : ['%stage%' => $stage->dayNumber],
                        $locale,
                    );
                } elseif ('7' === $stageDate->format('N')) {
                    $alerts[] = $this->buildAlert(
                        $i,
                        $stage,
                        $stageDate,
                        AlertCode::CALENDAR_SUNDAY,
                        'alert.calendar.sunday_nudge',
                        ['%stage%' => $stage->dayNumber],
                        $locale,
                    );
                }
            }

            $this->publisher->publish($tripId, MercureEventType::CALENDAR_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return array{stageIndex: int, dayNumber: int, code: string, type: string, date: string, message: string, action: array{kind: string, label: string, payload: array<string, mixed>}}
     */
    private function buildAlert(int $stageIndex, Stage $stage, \DateTimeImmutable $stageDate, AlertCode $code, string $translationKey, array $parameters, string $locale): array
    {
        return [
            'stageIndex' => $stageIndex,
            'dayNumber' => $stage->dayNumber,
            'code' => $code->value,
            'type' => AlertType::NUDGE->value,
            'date' => $stageDate->format('Y-m-d'),
            'message' => $this->translator->trans($translationKey, $parameters, 'alerts', $locale),
            'action' => [
                'kind' => AlertActionKind::DISMISS->value,
                'label' => $this->translator->trans('alert.calendar.action', [], 'alerts', $locale),
                'payload' => [],
            ],
        ];
    }

    /**
     * One Yasumi provider per (country, year) pair covered by the trip.
     *
     * @param list<Stage> $stages
     *
     * @return list<ProviderInterface>
     */
    private function resolveProviders(array $stages, \DateTimeImmutable $startDate, string $locale): array
    {
        $years = $this->resolveYears($stages, $startDate);
        $yasumiLocale = \in_array($locale, Yasumi::getAvailableLocales(), true) ? $locale : self::FALLBACK_YASUMI_LOCALE;

        $countryCodes = $this->resolveCountryCodes($stages);
        if ([] === $countryCodes) {
            $countryCodes = [self::FALLBACK_COUNTRY_CODE];
        }

        $providers = $this->createProviders($countryCodes, $years, $yasumiLocale);

        // Every resolved country lacks a Yasumi provider: fall back to France rather
        // than reporting no holiday at all.
        if ([] === $providers && [self::FALLBACK_COUNTRY_CODE] !== $countryCodes) {
            $providers = $this->createProviders([self::FALLBACK_COUNTRY_CODE], $years, $yasumiLocale);
        }

        return $providers;
    }

    /**
     * @param list<string> $countryCodes
     * @param list<int>    $years
     *
     * @return list<ProviderInterface>
     */
    private function createProviders(array $countryCodes, array $years, string $yasumiLocale): array
    {
        $providers = [];

        foreach ($countryCodes as $countryCode) {
            foreach ($years as $year) {
                try {
                    $providers[] = Yasumi::createByISO3166_2($countryCode, $year, $yasumiLocale);
                } catch (\Throwable $throwable) {
                    $this->logger->warning('No holiday provider for country {country} in {year}.', [
                        'country' => $countryCode,
                        'year' => $year,
                        'exception' => $throwable,
                    ]);
                }
            }
        }

        return $providers;
    }

    /**
     * Every calendar year the trip spans (one day per stage, rest days included).
     *
     * @param list<Stage> $stages
     *
     * @return list<int>
     */
    private function resolveYears(array $stages, \DateTimeImmutable $startDate): array
    {
        $endDate = $startDate->modify(\sprintf('+%d days', max(0, \count($stages) - 1)));

        $years = [];
        for ($year = (int) $startDate->format('Y'), $lastYear = (int) $endDate->format('Y'); $year <= $lastYear; ++$year) {
            $years[] = $year;
        }

        return $years;
    }

    /**
     * The distinct ISO 3166-1 alpha-2 codes of the countries the route runs through,
     * resolved from the local admin-boundary index (ADR-040) at the first stage's start
     * point and at every stage end point.
     *
     * @param list<Stage> $stages
     *
     * @return list<string>
     */
    private function resolveCountryCodes(array $stages): array
    {
        if ([] === $stages) {
            return [];
        }

        $points = [$stages[0]->startPoint];
        foreach ($stages as $stage) {
            $points[] = $stage->endPoint;
        }

        $codes = [];
        foreach ($points as $point) {
            $code = $this->adminBoundaryRepository->findCountryCodeAt($point->lat, $point->lon);
            if (null !== $code && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param list<ProviderInterface> $providers
     */
    private function findHoliday(array $providers, \DateTimeImmutable $date): ?Holiday
    {
        $day = $date->format('Y-m-d');

        foreach ($providers as $provider) {
            foreach ($provider->getHolidays() as $holiday) {
                if ($holiday->format('Y-m-d') === $day) {
                    return $holiday;
                }
            }
        }

        return null;
    }

    /**
     * The translated holiday name, or null when Yasumi has no translation for the
     * active locale. Callers then emit a message without a name rather than the
     * tautological "coincides with a public holiday (Public holiday)".
     */
    private function resolveHolidayName(Holiday $holiday): ?string
    {
        try {
            $name = $holiday->getName();
        } catch (\Throwable) {
            return null;
        }

        return '' !== $name ? $name : null;
    }
}
