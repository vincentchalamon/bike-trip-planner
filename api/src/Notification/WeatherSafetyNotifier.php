<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Stage;
use App\Enum\NotificationCategory;
use App\Repository\OwnedTripFinderInterface;

/**
 * Pushes the `weatherSafety` notification for the stage a rider tackles on a given
 * day (#1124): the forecast plus the count of safety alerts for that stage.
 *
 * There is no Symfony Scheduler in this project, so the trigger is a console
 * command ({@see \App\Command\NotifyWeatherSafetyCommand}) that ops schedules
 * twice a day: the evening before (`--day=tomorrow`) and the morning of
 * (`--day=today`). Each call notifies every owned trip whose ridden stage lands
 * on the target day, for owners who kept the category enabled.
 */
final readonly class WeatherSafetyNotifier
{
    public function __construct(
        private OwnedTripFinderInterface $tripRepository,
        private NotificationDispatcherInterface $dispatcher,
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

            $dispatched += $this->dispatcher->dispatch(
                $trip->user->getId()->toRfc4122(),
                NotificationCategory::WEATHER_SAFETY,
                \sprintf('Étape J%d — %s', $dayNumber, $this->weatherHeadline($stage)),
                $this->body($stage),
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

    private function weatherHeadline(Stage $stage): string
    {
        $weather = $stage->getWeather();
        if (null === $weather) {
            return 'météo à venir';
        }

        $description = \is_string($weather['description'] ?? null) ? $weather['description'] : 'météo';
        $min = $weather['tempMin'] ?? null;
        $max = $weather['tempMax'] ?? null;

        if (is_numeric($min) && is_numeric($max)) {
            return \sprintf('%s, %d–%d °C', $description, (int) round((float) $min), (int) round((float) $max));
        }

        return $description;
    }

    private function body(Stage $stage): string
    {
        $alerts = \count($stage->getAlerts());

        if (0 === $alerts) {
            return 'Aucune alerte de sécurité signalée pour cette étape. Bonne route !';
        }

        return \sprintf('%d alerte%s de sécurité sur cette étape. Ouvrez l\'app pour les détails.', $alerts, $alerts > 1 ? 's' : '');
    }
}
