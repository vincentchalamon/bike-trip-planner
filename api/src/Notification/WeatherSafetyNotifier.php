<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Stage;
use App\Enum\NotificationCategory;
use App\Repository\OwnedTripFinderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pushes the `weatherSafety` notification for the stage a rider tackles on a given
 * day (#1124): the forecast plus the count of safety alerts for that stage.
 *
 * There is no Symfony Scheduler in this project, so the trigger is a console
 * command ({@see \App\Command\NotifyWeatherSafetyCommand}) that ops schedules
 * twice a day: the evening before (`--day=tomorrow`) and the morning of
 * (`--day=today`). Each call notifies every owned trip whose ridden stage lands
 * on the target day, for owners who kept the category enabled. Copy is localised
 * to the trip's locale (falling back to English).
 */
final readonly class WeatherSafetyNotifier
{
    public function __construct(
        private OwnedTripFinderInterface $tripRepository,
        private NotificationDispatcherInterface $dispatcher,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return int the number of pushes dispatched
     */
    public function notify(\DateTimeImmutable $date): int
    {
        $day = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
        $dispatched = 0;

        foreach ($this->tripRepository->findOwnedTripsCoveringDate($day) as $trip) {
            if (in_array(null, [$trip->startDate, $trip->user, $trip->id], true)) {
                continue;
            }

            $dayNumber = $trip->startDate->diff($day)->days + 1;
            $stage = $this->stageOnDay($trip->stages, $dayNumber);
            if (!$stage instanceof Stage) {
                continue;
            }

            $locale = '' !== $trip->locale ? $trip->locale : 'en';
            $title = $this->translator->trans(
                'notification.weather_safety.title',
                ['%day%' => $dayNumber, '%headline%' => $this->weatherHeadline($stage, $locale)],
                'notifications',
                $locale,
            );

            $dispatched += $this->dispatcher->dispatch(
                $trip->user->getId()->toRfc4122(),
                NotificationCategory::WEATHER_SAFETY,
                $title,
                $this->body($stage, $locale),
                ['tripId' => $trip->id->toRfc4122(), 'dayNumber' => (string) $dayNumber],
            ) ? 1 : 0;
        }

        return $dispatched;
    }

    /**
     * @param iterable<Stage> $stages
     */
    private function stageOnDay(iterable $stages, int $dayNumber): ?Stage
    {
        foreach ($stages as $stage) {
            if ($stage->getDayNumber() === $dayNumber && !$stage->isRestDay()) {
                return $stage;
            }
        }

        return null;
    }

    private function weatherHeadline(Stage $stage, string $locale): string
    {
        $weather = $stage->getWeather();
        if (null === $weather) {
            return $this->translator->trans('notification.weather_safety.headline.pending', [], 'notifications', $locale);
        }

        $description = \is_string($weather['description'] ?? null) ? $weather['description'] : '';
        $min = $weather['tempMin'] ?? null;
        $max = $weather['tempMax'] ?? null;

        if ('' !== $description && is_numeric($min) && is_numeric($max)) {
            return $this->translator->trans(
                'notification.weather_safety.headline.forecast',
                [
                    '%description%' => $description,
                    '%min%' => (int) round((float) $min),
                    '%max%' => (int) round((float) $max),
                ],
                'notifications',
                $locale,
            );
        }

        return '' !== $description
            ? $description
            : $this->translator->trans('notification.weather_safety.headline.pending', [], 'notifications', $locale);
    }

    private function body(Stage $stage, string $locale): string
    {
        $alerts = \count($stage->getAlerts());

        if (0 === $alerts) {
            return $this->translator->trans('notification.weather_safety.body.none', [], 'notifications', $locale);
        }

        return $this->translator->trans('notification.weather_safety.body.some', ['%count%' => $alerts], 'notifications', $locale);
    }
}
